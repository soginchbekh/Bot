<?php
/* ============================================================
   CONFIG
============================================================ */
$bot_token    = "8682051240:AAHPUBzc_K_BIt77S3RdgPPpWvVG_0ARb64";
$api          = "https://api.telegram.org/bot{$bot_token}/";
$groq_key     = "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";
$admin_id     = 8543048560;
$bot_username = "Lingo_Check_bot";
$default_photo = "https://t.me/smm_craft_uz/90";

/* ============================================================
   DATABASE
============================================================ */
$db = new PDO("sqlite:bot.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");

$db->exec("CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tg_id INTEGER UNIQUE,
    username TEXT DEFAULT '',
    full_name TEXT DEFAULT '',
    balance REAL DEFAULT 0,
    checks INTEGER DEFAULT 3,
    premium INTEGER DEFAULT 0,
    premium_expire INTEGER DEFAULT 0,
    referrer INTEGER DEFAULT 0,
    ref_count INTEGER DEFAULT 0,
    ref_earned REAL DEFAULT 0,
    state TEXT DEFAULT '',
    state_data TEXT DEFAULT '',
    joined_at INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS channels(id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS wallets(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, number TEXT, holder TEXT, description TEXT DEFAULT '', active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE IF NOT EXISTS payments(id INTEGER PRIMARY KEY AUTOINCREMENT, tg_id INTEGER, amount REAL, wallet_id INTEGER, check_file TEXT DEFAULT '', status TEXT DEFAULT 'pending', created INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS speaking_rules(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    part INTEGER UNIQUE,
    content TEXT DEFAULT '',
    entities_json TEXT DEFAULT ''
)");

// speaking_questions: part 1 = text only, part 2/3 = photo_file_id + question (separate rows type)
$db->exec("CREATE TABLE IF NOT EXISTS speaking_questions(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    part INTEGER DEFAULT 1,
    question TEXT DEFAULT '',
    photo_file_id TEXT DEFAULT '',
    active INTEGER DEFAULT 1
)");

foreach([
    'bot_active'=>'1','premium_price'=>'50000','premium_checks'=>'400',
    'ref_reward'=>'5000','free_checks'=>'3','currency'=>'UZS',
    'off_message'=>"Bot hozircha to'xtatilgan.",
] as $k=>$v){
    $db->prepare("INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)")->execute([$k,$v]);
}

// Default Part 1 questions (text only)
$check_q = $db->query("SELECT COUNT(*) FROM speaking_questions WHERE part=1")->fetchColumn();
if($check_q == 0){
    $p1qs = [
        "#savol1\nTell me about yourself. Where are you from and what do you do?",
        "#savol2\nWhat do you like to do in your free time?",
        "#savol3\nDescribe your hometown. What do you like about it?",
        "#savol4\nWhat is your favourite season and why?",
        "#savol5\nDo you prefer living in a city or in the countryside?",
        "#savol6\nTell me about your daily routine.",
        "#savol7\nWhat kind of music do you enjoy listening to?",
    ];
    $ins = $db->prepare("INSERT INTO speaking_questions(part,question,photo_file_id,active) VALUES(1,?,'',1)");
    foreach($p1qs as $q) $ins->execute([$q]);
}

/* ============================================================
   PARSE UPDATE
============================================================ */
$raw    = file_get_contents("php://input");
$update = json_decode($raw, true);
if(!$update){ exit; }

$message   = $update["message"]        ?? null;
$callback  = $update["callback_query"] ?? null;
$photo_msg = null;
$voice_msg = null;
$audio_msg = null;

$text = $cdata = $uname = $full_name = "";
$user_id = $chat_id = null;

if($message){
    $user_id   = $message["from"]["id"]       ?? null;
    $chat_id   = $message["chat"]["id"]        ?? null;
    $text      = $message["text"]              ?? "";
    $uname     = $message["from"]["username"]  ?? "";
    $full_name = trim(($message["from"]["first_name"]??"")." ".($message["from"]["last_name"]??""));
    if(!empty($message["photo"])) $photo_msg = $message;
    if(!empty($message["voice"])) $voice_msg = $message;
    if(!empty($message["audio"])) $audio_msg = $message;
}
if($callback){
    $user_id   = $callback["from"]["id"]                    ?? null;
    $chat_id   = $callback["message"]["chat"]["id"]         ?? null;
    $cdata     = $callback["data"]                          ?? "";
    $uname     = $callback["from"]["username"]              ?? "";
    $full_name = trim(($callback["from"]["first_name"]??"")." ".($callback["from"]["last_name"]??""));
}

if(!$user_id || !$chat_id){ exit; }

/* ============================================================
   HELPERS
============================================================ */
function db(){ global $db; return $db; }
function getSetting($k){ $q=db()->prepare("SELECT value FROM settings WHERE key=?"); $q->execute([$k]); return $q->fetchColumn(); }
function setSetting($k,$v){ db()->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)")->execute([$k,$v]); }
function getUser($id){ $q=db()->prepare("SELECT * FROM users WHERE tg_id=?"); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC); }
function createUser($id,$u,$fn,$ref=0){ $free=(int)getSetting('free_checks'); db()->prepare("INSERT OR IGNORE INTO users(tg_id,username,full_name,checks,referrer,joined_at) VALUES(?,?,?,?,?,?)")->execute([$id,$u,$fn,$free,$ref,time()]); }
function setState($id,$s,$d=''){ db()->prepare("UPDATE users SET state=?,state_data=? WHERE tg_id=?")->execute([$s,$d,$id]); }
function clearState($id){ setState($id,'',''); }
function isPremium($u){ return isset($u["premium"]) && $u["premium"]==1 && $u["premium_expire"]>time(); }
function fmt($n){ return number_format((float)$n,0,'.',' '); }
function getChannels(){ return db()->query("SELECT username FROM channels")->fetchAll(PDO::FETCH_COLUMN); }
function getSpeakingRule($part){ $q=db()->prepare("SELECT * FROM speaking_rules WHERE part=?"); $q->execute([$part]); return $q->fetch(PDO::FETCH_ASSOC); }

function checkJoin($uid){
    global $api;
    $chs=getChannels(); if(empty($chs)) return true;
    foreach($chs as $ch){
        $c=curl_init($api."getChatMember?chat_id=".urlencode($ch)."&user_id=".$uid);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        $d=json_decode(curl_exec($c),true); curl_close($c);
        $s=$d["result"]["status"]??"";
        if(!in_array($s,["member","administrator","creator"])) return false;
    }
    return true;
}

// Part 1 => 3 random text questions
// Part 2/3 => 1 random question with photo
function getRandomSpeakingQuestions($part, $count=3){
    $q = db()->prepare("SELECT * FROM speaking_questions WHERE part=? AND active=1 ORDER BY RANDOM() LIMIT ?");
    $q->execute([$part, $count]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/* ============================================================
   TELEGRAM API
============================================================ */
function sendMsg($chat,$text,$kb=null){
    global $api;
    $p=["chat_id"=>$chat,"text"=>$text,"parse_mode"=>"HTML"];
    if($kb) $p["reply_markup"]=json_encode($kb);
    $ch=curl_init($api."sendMessage");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p]);
    $r=curl_exec($ch); curl_close($ch);
    return json_decode($r,true);
}
function editMsg($chat,$mid,$text,$kb=null){
    global $api;
    $p=["chat_id"=>$chat,"message_id"=>$mid,"text"=>$text,"parse_mode"=>"HTML"];
    if($kb) $p["reply_markup"]=json_encode($kb);
    $ch=curl_init($api."editMessageText");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p]);
    curl_exec($ch); curl_close($ch);
}
function answerCb($id,$t=""){ global $api; $ch=curl_init($api."answerCallbackQuery"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>["callback_query_id"=>$id,"text"=>$t]]); curl_exec($ch); curl_close($ch); }
function sendPhoto($chat,$photo,$caption="",$kb=null){
    global $api;
    $p=["chat_id"=>$chat,"photo"=>$photo,"caption"=>$caption,"parse_mode"=>"HTML"];
    if($kb) $p["reply_markup"]=json_encode($kb);
    $ch=curl_init($api."sendPhoto");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p]);
    $r=curl_exec($ch); curl_close($ch);
    return json_decode($r,true);
}
function copyMessage($to_chat, $from_chat, $msg_id){
    global $api;
    $p=["chat_id"=>$to_chat,"from_chat_id"=>$from_chat,"message_id"=>$msg_id];
    $ch=curl_init($api."copyMessage");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p]);
    $r=curl_exec($ch); curl_close($ch);
    return json_decode($r,true);
}
function getUserPhotoId($uid){
    global $api;
    $ch=curl_init($api."getUserProfilePhotos?user_id={$uid}&limit=1");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    if(!empty($d["result"]["photos"][0])){ $p=$d["result"]["photos"][0]; return end($p)["file_id"]; }
    return null;
}
function getFileUrl($file_id){
    global $api, $bot_token;
    $ch=curl_init($api."getFile?file_id={$file_id}");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    if(!empty($d["result"]["file_path"])){
        return "https://api.telegram.org/file/bot{$bot_token}/".$d["result"]["file_path"];
    }
    return null;
}

/* ============================================================
   GROQ CALL (text)
============================================================ */
function groqCall($prompt, $max_tokens=1000){
    global $groq_key;
    $data=["model"=>"llama-3.3-70b-versatile","temperature"=>0.2,"max_tokens"=>$max_tokens,
        "messages"=>[["role"=>"user","content"=>$prompt]]];
    $ch=curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer ".$groq_key],
        CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_TIMEOUT=>60]);
    $r=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    if($err) return "❌ Tarmoq xatoligi: ".$err;
    $a=json_decode($r,true);
    if(isset($a["error"])) return "❌ AI xatolik: ".($a["error"]["message"]??"");
    return $a["choices"][0]["message"]["content"] ?? "❌ AI javob bermadi.";
}

/* ============================================================
   GROQ WHISPER — AUDIO → TEXT
============================================================ */
function transcribeAudio($file_url){
    global $groq_key;
    $tmp = tempnam(sys_get_temp_dir(),"spk_").".ogg";
    $ch=curl_init($file_url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>true]);
    $data=curl_exec($ch); curl_close($ch);
    if(!$data || strlen($data)<100) return null;
    file_put_contents($tmp,$data);

    $ch=curl_init("https://api.groq.com/openai/v1/audio/transcriptions");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer ".$groq_key],
        CURLOPT_POSTFIELDS=>["file"=>new CURLFile($tmp,"audio/ogg","audio.ogg"),
            "model"=>"whisper-large-v3-turbo","language"=>"en","response_format"=>"json"],
        CURLOPT_TIMEOUT=>60]);
    $r=curl_exec($ch); curl_close($ch);
    @unlink($tmp);
    $a=json_decode($r,true);
    return isset($a["text"]) ? trim($a["text"]) : null;
}

/* ============================================================
   AI — WRITING FREE
============================================================ */
function aiWritingFree($essay){
    $prompt="You are an Multilevel Writing Examiner (Uzbekistan system B1/B2/C1).\n\nAnalyze the user's writing using ONLY 3 sections:\n1) Task Achievement\n2) Coherence & Cohesion\n3) Lexical Resource & Grammar\n\nRules:\n- Each section gives score from 1 to 4\n- Total score = /12\n- Level: 10-12=B2/C1 PASS, 7-9=B1+/B2 borderline, 4-6=B1, 0-3=A2\n- If off-topic OR too short → minimum score\n\nResponse format (SHORT):\nScore:\nTask: X/4\nCoherence: X/4\nLanguage: X/4\nTotal: X/12\n\nLevel: (write level)\n\nFeedback:\n- 3-5 short bullet points only\n\nRespond ONLY in Uzbek.\n\nUser text: ".$essay;
    return groqCall($prompt, 800);
}

/* ============================================================
   AI — WRITING PREMIUM
============================================================ */
function aiWritingPremium($essay){
    $prompt="You are a professional Uzbekistan Multilevel Writing Examiner (B1-B2-C1).\n\nSTEP 1 — Identify:\n- Task type (formal/informal/essay)\n- Check if style matches\n\nSTEP 2 — Score (1-4 each):\n1) Task Achievement\n2) Coherence & Cohesion\n3) Lexical + Grammar\n\nSTEP 3 — Total /12:\n10-12→B2/C1 PASS, 7-9→B1+/B2, 4-6→B1, 0-3→A2\n\nSTEP 4 — Detailed Feedback:\n1. Strengths\n2. Mistakes (grammar+vocab corrections)\n3. Style check\n4. Improvement tips\n\nSTEP 5 — IMPROVED VERSION (rewrite in correct style)\n\nFORMAT:\n📊 SCORE\nTask: X/4\nCoherence: X/4\nLanguage: X/4\nTotal: X/12\nLevel: ___\n\n🧠 ANALYSIS\n(detailed)\n\n❌ ERRORS & CORRECTIONS\n(sentence → corrected)\n\n✨ IMPROVED VERSION\n(full corrected text)\n\nRespond ONLY in Uzbek.\n\nUser text: ".$essay;
    return groqCall($prompt, 2500);
}

/* ============================================================
   AI — SPEAKING FREE
============================================================ */
function aiSpeakingFree($transcript, $question=""){
    $q_line = $question ? "\nQuestion asked: ".$question : "";
    $prompt="You are an English Speaking Examiner.\n\nEvaluate the response using 5 criteria:\n- Vocabulary\n- Grammar\n- Fluency\n- Communication\n- Pronunciation\n\nScore each 0-6. Level: 5-6→B2/C1, 4→B2, 3→B1, 0-2→Below B1\nIf answer too short → low score\n".$q_line."\n\nOUTPUT (SHORT):\nScore:\nVocabulary: X/6\nGrammar: X/6\nFluency: X/6\nCommunication: X/6\nPronunciation: X/6\n\nAverage: X.X\nLevel: ___\n\nFeedback:\n- 3-5 short points\n\nRespond ONLY in Uzbek.\n\nUser answer: ".$transcript;
    return groqCall($prompt, 700);
}

/* ============================================================
   AI — SPEAKING PREMIUM
============================================================ */
function aiSpeakingPremium($transcript, $question=""){
    $q_line = $question ? "\nQuestion asked: ".$question : "";
    $prompt="You are a professional Uzbekistan Multilevel Speaking Examiner (CEFR B1-B2-C1).\n\nScore using 5 Criteria (0-6 each):\n1) Vocabulary\n2) Grammar\n3) Fluency & Coherence\n4) Communicative Effectiveness\n5) Pronunciation\n\nAverage → Level:\n5.5-6.0→C1, 4.0-5.4→B2(PASS), 3.0-3.9→B1, 0-2.9→Below B1\nPASS: ≥4.0\n".$q_line."\n\nOUTPUT FORMAT:\n📊 SCORE\nVocabulary: X/6\nGrammar: X/6\nFluency: X/6\nCommunication: X/6\nPronunciation: X/6\nAverage: X.X\nLevel: ___\nResult: PASS/FAIL\n\n🧠 ANALYSIS\n(detailed)\n\n❌ ERRORS & CORRECTIONS\n(sentence → corrected)\n\n✨ IMPROVED VERSION\n(full improved answer)\n\nRespond ONLY in Uzbek.\n\nUser answer: ".$transcript;
    return groqCall($prompt, 2500);
}

/* ============================================================
   AI — SPEAKING PART 1 FREE (3 javob birga)
============================================================ */
function aiSpeakingPart1Free($qa_pairs){
    // $qa_pairs = [['q'=>'...','a'=>'...'], ...]
    $combined="";
    foreach($qa_pairs as $i=>$pair){
        $combined.="Q".($i+1).": ".($pair['q']??'')."\nA".($i+1).": ".($pair['a']??'')."\n\n";
    }
    $prompt="You are an English Speaking Examiner (CEFR).\n\nThe student answered 3 Part 1 Interview questions. Evaluate ALL 3 answers TOGETHER as one speaking session.\n\nScore each criterion 0-6 based on overall performance across all answers:\n- Vocabulary\n- Grammar\n- Fluency\n- Communication\n- Pronunciation\n\nLevel: 5-6→B2/C1, 4→B2, 3→B1, 0-2→Below B1\n\nOUTPUT (SHORT):\n📊 UMUMIY BAHO (Part 1 — 3 savol):\nVocabulary: X/6\nGrammar: X/6\nFluency: X/6\nCommunication: X/6\nPronunciation: X/6\n\nO'rtacha: X.X\nDaraja: ___\n\nFeedback:\n- 3-5 qisqa fikr\n\nRespond ONLY in Uzbek.\n\nStudent's answers:\n".$combined;
    return groqCall($prompt, 800);
}

/* ============================================================
   AI — SPEAKING PART 1 PREMIUM (3 javob birga)
============================================================ */
function aiSpeakingPart1Premium($qa_pairs){
    $combined="";
    foreach($qa_pairs as $i=>$pair){
        $combined.="Q".($i+1).": ".($pair['q']??'')."\nA".($i+1).": ".($pair['a']??'')."\n\n";
    }
    $prompt="You are a professional Uzbekistan Multilevel Speaking Examiner (CEFR B1-B2-C1).\n\nThe student completed a full Part 1 Interview (3 questions). Evaluate ALL 3 answers TOGETHER as one complete session.\n\nScore using 5 Criteria (0-6 each) based on overall performance:\n1) Vocabulary\n2) Grammar\n3) Fluency & Coherence\n4) Communicative Effectiveness\n5) Pronunciation\n\nAverage → Level:\n5.5-6.0→C1, 4.0-5.4→B2(PASS), 3.0-3.9→B1, 0-2.9→Below B1\nPASS: ≥4.0\n\nOUTPUT FORMAT:\n📊 UMUMIY BAHO — Part 1 (3 savol)\nVocabulary: X/6\nGrammar: X/6\nFluency: X/6\nCommunication: X/6\nPronunciation: X/6\nO'rtacha: X.X\nDaraja: ___\nNatija: PASS/FAIL\n\n🧠 TAHLIL (har 3 javob bo'yicha umumiy)\n(detailed)\n\n❌ XATOLAR & TUZATISHLAR\n(eng muhim xatolar sentence → corrected)\n\n✨ YAXSHILANGAN VERSIYA\n(Q1 va Q2 uchun namuna javob)\n\nRespond ONLY in Uzbek.\n\nStudent's answers:\n".$combined;
    return groqCall($prompt, 2800);
}

/* ============================================================
   AI — WRITING (old task-based, kept for compatibility)
============================================================ */
function aiCheck($essay, $task_code, $question){
    $info = getTaskInfo($task_code);
    if(!$info) return "❌ Noma'lum task.";
    $task_name=$info["name"]; $format=$info["format"]; $register=$info["register"];
    $min_w=$info["min_words"]; $max_w=$info["max_words"];
    $crit_ta=($task_code==="2")?"Task Response (TR)":"Task Achievement (TA)";
    $system="You are an expert CEFR writing examiner. Task: {$task_name} | Format: {$format} | Register: {$register}\nWord limit: {$min_w}–{$max_w} words.\nStudent's writing prompt:\n---\n{$question}\n---\nEvaluate based on CEFR multilevel descriptors (A1–C2).\nSCORING: 0=A1|1=A2|2=B1|3=B2|4=C1|5=C2\nRESPOND ONLY IN UZBEK.\n\n━━━━━━━━━━━━━━━━━━━━━━\n📝 {$task_name}\n📋 {$format} | {$register}\n━━━━━━━━━━━━━━━━━━━━━━\n\n🎯 CEFR Daraja: [A1/A2/B1/B2/C1/C2]\n📊 Umumiy ball: [0-5]/5\n\n━━━ KRITERIYLAR ━━━\n1️⃣ {$crit_ta}: [0-5]\n├ Daraja: [level]\n└ Izoh: [explanation]\n\n2️⃣ Coherence & Cohesion: [0-5]\n├ Daraja: [level]\n└ Izoh: [explanation]\n\n3️⃣ Lexical Resource: [0-5]\n├ Daraja: [level]\n└ Izoh: [explanation]\n\n4️⃣ Grammatical Range & Accuracy: [0-5]\n├ Daraja: [level]\n└ Izoh: [explanation]\n\n━━━ FEEDBACK ━━━\n✅ Kuchli tomonlar:\n[2-3 ta misol]\n\n⚠️ Yaxshilash kerak:\n[2-3 ta maslahat]\n\n━━━ C1 NAMUNA JAVOB ━━━\n💡 C1 darajasida namuna:\n[Ingliz tilida to'liq namuna]";
    global $groq_key;
    $data=["model"=>"llama-3.3-70b-versatile","temperature"=>0.15,"max_tokens"=>2500,
        "messages"=>[["role"=>"system","content"=>$system],["role"=>"user","content"=>"Student's writing:\n\n".$essay]]];
    $ch=curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer ".$groq_key],
        CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_TIMEOUT=>60]);
    $r=curl_exec($ch); curl_close($ch);
    $a=json_decode($r,true);
    if(isset($a["error"])) return "❌ AI xatolik: ".($a["error"]["message"]??"");
    return $a["choices"][0]["message"]["content"] ?? "❌ AI javob bermadi.";
}

/* ============================================================
   KEYBOARDS
============================================================ */
function mainKb($is_admin=false){
    // Row 1: WRITING + SPEAKING
    // Row 2: Hisobim + Referral
    // Row 3: Premium
    // Row 4 (admin): Panel
    $rows=[
        [["text"=>"WRITING 📝"],["text"=>"SPEAKING 🎙️"]],
        [["text"=>"👤 Hisobim"],["text"=>"💰 Pul ishlash"]],
        [["text"=>"⭐ Premium"]],
    ];
    if($is_admin) $rows[]=[["text"=>"🛠 Panel"]];
    return ["keyboard"=>$rows,"resize_keyboard"=>true];
}
function backKb(){ return ["keyboard"=>[[["text"=>"🔙 Orqaga"]]],"resize_keyboard"=>true]; }
function speakingKb(){
    return ["keyboard"=>[
        [["text"=>"⏸ Pauza"],["text"=>"▶️ Davom"]],
        [["text"=>"⏹ To'xtatish"]],
    ],"resize_keyboard"=>true];
}
// Faqat to'xtatish tugmasi (javob vaqtida)
function stopOnlyKb(){
    return ["keyboard"=>[[["text"=>"⏹ To'xtatish"]]],"resize_keyboard"=>true];
}
// Keyboard olib tashlash (tayyorlanish vaqtida)
function noKb(){
    return ["remove_keyboard"=>true];
}
function panelKb(){
    return ["keyboard"=>[
        [["text"=>"📢 Majburiy kanal"],["text"=>"💳 Hamyon sozlash"]],
        [["text"=>"👥 Referral sozlash"],["text"=>"💰 Narx sozlash"]],
        [["text"=>"🤖 Bot holati"],["text"=>"📊 Statistika"]],
        [["text"=>"👤 Foydalanuvchi"],["text"=>"📣 Reklama yuborish"]],
        [["text"=>"🎤 Speaking savollar"],["text"=>"📜 Speak Qoida"]],
        [["text"=>"🔙 Bosh menyu"]]
    ],"resize_keyboard"=>true];
}
function backPanel(){ return ["keyboard"=>[[["text"=>"🔙 Panel"]]],"resize_keyboard"=>true]; }
function writingTaskKb(){
    return ["inline_keyboard"=>[
        [["text"=>"✉️ Task 1.1","callback_data"=>"wtask_1_1"]],
        [["text"=>"📄 Task 1.2","callback_data"=>"wtask_1_2"]],
        [["text"=>"📝 Task 2","callback_data"=>"wtask_2"]],
    ]];
}
function speakingMenuKb(){
    return ["inline_keyboard"=>[
        [["text"=>"🎯 PART 1","callback_data"=>"speak_part1"]],
        [["text"=>"📖 PART 2","callback_data"=>"speak_part2"]],
        [["text"=>"💬 PART 3","callback_data"=>"speak_part3"]],
    ]];
}

/* ============================================================
   TASK INFO
============================================================ */
function getTaskInfo($code){
    $tasks=[
        "1_1"=>["name"=>"Task 1.1 — Informal Letter","task_num"=>"Task 1.1","format"=>"Informal Letter",
            "register"=>"Informal (norasmiy)","desc"=>"Do'st yoki tanishga yozilgan norasmiy xat.",
            "tips_uz"=>"💡 Hi/Hey bilan boshlang. Qisqartmalar ishlating (I'm, can't, you're).",
            "example_q"=>"You are going to visit your friend next month.\nWrite a letter to your friend.\nWrite up to 50 words.",
            "min_words"=>10,"max_words"=>60],
        "1_2"=>["name"=>"Task 1.2 — Formal Letter","task_num"=>"Task 1.2","format"=>"Formal Letter",
            "register"=>"Formal (rasmiy)","desc"=>"Rasmiy tashkilot yoki mansabdor shaxsga rasmiy xat.",
            "tips_uz"=>"💡 Dear Sir/Madam bilan boshlang. Yours faithfully bilan yakunlang.",
            "example_q"=>"You recently bought a damaged product. Write a letter to the manager. Write 120-150 words.",
            "min_words"=>80,"max_words"=>165],
        "2"=>["name"=>"Task 2 — Formal Essay","task_num"=>"Task 2","format"=>"Formal Essay",
            "register"=>"Formal (akademik)","desc"=>"Berilgan mavzu bo'yicha rasmiy fikr-mulohaza essay.",
            "tips_uz"=>"💡 Kirish (thesis) + 2 asosiy paragraf + Xulosa.",
            "example_q"=>"Some people think technology has made our lives more complicated. Discuss both views. Write 180-200 words.",
            "min_words"=>150,"max_words"=>215],
    ];
    return $tasks[$code] ?? null;
}

/* ============================================================
   TIMER HELPER — sends countdown messages
   Part1: 5s prep + 30s answer per question
   Part2/3: 50s prep + 120s answer
============================================================ */
// Timer prep: har 1 soniyada state VA state_data tekshiriladi
// state_data=paused|... bo'lsa to'xtaydi (pauza), state=stopped bo'lsa to'xtaydi
// Qaytadi: 'done'=tugadi, 'stopped'=to'xtatildi, 'paused'=pauza, 'changed'=state o'zgardi
function sendTimerPrep($chat, $uid, $seconds, $expected_state=''){
    $bar_total = min($seconds, 10);
    $r = sendMsg($chat, "🧠 <b>Tayyorlanish:</b>\n\n".str_repeat("░",$bar_total)." ⏳ <b>{$seconds}</b> soniya...");
    $mid = $r["result"]["message_id"] ?? null;
    for($i = $seconds; $i >= 1; $i--){
        sleep(1);
        $cur = db()->prepare("SELECT state,state_data FROM users WHERE tg_id=?");
        $cur->execute([$uid]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $cur_state = $row["state"] ?? "";
        $cur_data  = $row["state_data"] ?? "";
        // To'xtatish
        if($cur_state === '' || $cur_state === 'stopped'){
            if($mid) editMsg($chat,$mid,"⏹ <b>To'xtatildi.</b>");
            return 'stopped';
        }
        // Pauza — state_data da paused| flag bor
        if(strpos($cur_data,'paused|')===0){
            if($mid) editMsg($chat,$mid,"⏸ <b>Pauza qilindi.</b>");
            return 'paused';
        }
        // State o'zgardi (kutilmagan)
        if($expected_state !== '' && $cur_state !== $expected_state){
            return 'changed';
        }
        if($mid){
            $done = $bar_total - (int)round($i/$seconds*$bar_total);
            $left_b = $bar_total - $done;
            editMsg($chat,$mid,"🧠 <b>Tayyorlanish:</b>\n\n".str_repeat("▓",$done).str_repeat("░",$left_b)." ⏳ <b>{$i}</b> soniya...");
        }
    }
    if($mid) editMsg($chat,$mid,"✅ <b>Boshlang! Gapiring!</b> 🎤");
    return 'done';
}

// Timer answer: har 10 soniyada state VA state_data tekshiriladi
// Qaytadi: 'done'=tugadi, 'stopped'=to'xtatildi, 'paused'=pauza, 'changed'=state o'zgardi
function sendTimerAnswer($chat, $uid, $seconds, $expected_state=''){
    $r = sendMsg($chat, "🎤 <b>Javob vaqti:</b>\n\n██████████ ⏳ <b>{$seconds}</b> soniya qoldi...");
    $mid = $r["result"]["message_id"] ?? null;
    $steps = (int)floor($seconds / 10);
    for($s = 1; $s <= $steps; $s++){
        sleep(10);
        $cur = db()->prepare("SELECT state,state_data FROM users WHERE tg_id=?");
        $cur->execute([$uid]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $cur_state = $row["state"] ?? "";
        $cur_data  = $row["state_data"] ?? "";
        // To'xtatish
        if($cur_state === '' || $cur_state === 'stopped'){
            if($mid) editMsg($chat,$mid,"⏹ <b>To'xtatildi.</b>");
            return 'stopped';
        }
        // Pauza — state_data da paused| flag bor
        if(strpos($cur_data,'paused|')===0){
            if($mid) editMsg($chat,$mid,"⏸ <b>Pauza qilindi.</b>");
            return 'paused';
        }
        // State o'zgardi (masalan audio yuborildi)
        if($expected_state !== '' && $cur_state !== $expected_state){
            if($mid) editMsg($chat,$mid,"✅ <b>Javob qabul qilindi!</b>");
            return 'changed';
        }
        $left = $seconds - $s*10;
        $done = (int)round($s/$steps*10);
        $left_b = 10-$done;
        $txt = $left > 0
            ? "🎤 <b>Javob vaqti:</b>\n\n".str_repeat("█",$done).str_repeat("░",$left_b)." ⏳ <b>{$left}</b> soniya qoldi..."
            : "⏰ <b>Vaqt tugadi!</b> Audio yuboring 🎤";
        if($mid) editMsg($chat,$mid,$txt);
    }
    return 'done';
}

/* ============================================================
   USER INIT
============================================================ */
$ref_id = 0;
if($message && strpos($text,"/start")===0){
    $pts=explode(" ",$text);
    if(isset($pts[1]) && strpos($pts[1],"ref_")===0){
        $rid=(int)str_replace("ref_","",$pts[1]);
        if($rid!=$user_id) $ref_id=$rid;
    }
}
$user = getUser($user_id);
$is_new = false;
if(!$user){
    createUser($user_id,$uname,$full_name,$ref_id);
    $user = getUser($user_id);
    $is_new = true;
    if($ref_id>0){
        $ref_u=getUser($ref_id);
        if($ref_u){
            $rew=(float)getSetting('ref_reward'); $cur_s=getSetting('currency');
            db()->prepare("UPDATE users SET ref_count=ref_count+1,ref_earned=ref_earned+?,balance=balance+? WHERE tg_id=?")->execute([$rew,$rew,$ref_id]);
            sendMsg($ref_id,"🎉 <b>Yangi referral!</b>\n\n👤 ".htmlspecialchars($full_name)." siz orqali qo'shildi!\n💰 +".fmt($rew)." $cur_s hisobingizga o'tdi!");
        }
    }
} else {
    db()->prepare("UPDATE users SET username=?,full_name=? WHERE tg_id=?")->execute([$uname,$full_name,$user_id]);
}
$user  = getUser($user_id);
$state = $user["state"] ?? "";

/* ============================================================
   BOT HOLATI + FORCE JOIN
============================================================ */
if($user_id != $admin_id){
    if(getSetting('bot_active')!='1'){ sendMsg($chat_id,getSetting('off_message')); exit; }
    if(!checkJoin($user_id)){
        $chs=getChannels(); $kb=["inline_keyboard"=>[]];
        foreach($chs as $ch) $kb["inline_keyboard"][]=[["text"=>"📢 ".ltrim($ch,"@"),"url"=>"https://t.me/".ltrim($ch,"@")]];
        $kb["inline_keyboard"][]=[["text"=>"✅ Tekshirish","callback_data"=>"check_sub"]];
        sendMsg($chat_id,"❌ <b>Botdan foydalanish uchun kanallarga obuna bo'ling:</b>",$kb); exit;
    }
}

/* ============================================================
   NAVIGATION BUTTONS
============================================================ */
if($message && $text=="🔙 Orqaga"){
    clearState($user_id); sendMsg($chat_id,"🏠 <b>Bosh menyu</b>",mainKb($user_id==$admin_id)); exit;
}
if($message && $text=="⏹ To'xtatish"){
    $sp_states=["sp1_prep","sp1_ans","sp1_ans_proc","sp2_prep","sp2_timer","sp2_ans","sp2_ans_proc","sp3_prep","sp3_timer","sp3_ans","sp3_ans_proc"];
    if(in_array($state,$sp_states)){
        // "stopped" flag o'rnat — timer shu flagni ko'rib to'xtaydi
        setState($user_id,"stopped","");
        // clearState va bosh menyu xabari timer funksiyasi ichida yuboriladi
        // lekin agar timer allaqachon tugagan bo'lsa shu yerdan tozalaymiz
        sendMsg($chat_id,"⏹ <b>To'xtatildi.</b>\n\n🏠 Bosh menyuga qaytdingiz.",mainKb($user_id==$admin_id));
        clearState($user_id);
    }
    exit;
}
if($message && $text=="🔙 Panel" && $user_id==$admin_id){
    clearState($admin_id); sendMsg($chat_id,"🛠 <b>Admin Panel</b>",panelKb()); exit;
}
if($message && $text=="🔙 Bosh menyu"){
    clearState($user_id); sendMsg($chat_id,"🏠 <b>Bosh menyu</b>",mainKb($user_id==$admin_id)); exit;
}

/* ============================================================
   /start
============================================================ */
if($message && strpos($text,"/start")===0){
    clearState($user_id);
    if($is_new){
        $free=getSetting('free_checks');
        sendMsg($chat_id,"👋 <b>Xush kelibsiz!</b>\n\n🤖 <b>Lingo Check Bot</b>\n\n✍️ Writing va 🎙️ Speaking bo'limlari orqali ingliz tilingizdagi darajangizni bilib oling!\n\n🎁 Sizga <b>$free ta bepul tekshiruv</b> berildi!",mainKb($user_id==$admin_id));
    } else {
        sendMsg($chat_id,"🏠 <b>Bosh menyu</b>",mainKb($user_id==$admin_id));
    }
    exit;
}

/* ============================================================
   ADMIN PANEL
============================================================ */
if($message && $text=="🛠 Panel" && $user_id==$admin_id){
    clearState($admin_id); sendMsg($chat_id,"🛠 <b>Admin Panel</b>",panelKb()); exit;
}

/* ============================================================
   ADMIN STATE HANDLERS
============================================================ */
if($message && $user_id==$admin_id){

    // ---- SPEAKING SAVOL QO'SHISH (Part 1 — text only) ----
    if($state=="adm_add_speak_q_1"){
        if($text && $text[0]!="/" && $text!="🔙 Panel"){
            $lines=preg_split('/\r?\n/',$text);
            $cur=""; $added=0;
            foreach($lines as $line){
                if(strpos(trim($line),'#')===0 && !empty($cur)){
                    db()->prepare("INSERT INTO speaking_questions(part,question,photo_file_id,active) VALUES(1,?,'',1)")->execute([trim($cur)]);
                    $added++; $cur=$line."\n";
                } else { $cur.=$line."\n"; }
            }
            if(!empty(trim($cur))){
                db()->prepare("INSERT INTO speaking_questions(part,question,photo_file_id,active) VALUES(1,?,'',1)")->execute([trim($cur)]);
                $added++;
            }
            clearState($admin_id);
            $cnt=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=1")->fetchColumn();
            sendMsg($chat_id,"✅ <b>$added ta savol qo'shildi!</b>\nPart 1 jami: <b>$cnt ta</b>\n\n🛠 Panel:",panelKb()); exit;
        }
    }

    // ---- SPEAKING SAVOL QO'SHISH (Part 2/3 — STEP 1: rasm kutish) ----
    if(in_array($state,["adm_add_speak_q_2_photo","adm_add_speak_q_3_photo"])){
        $part_n = ($state=="adm_add_speak_q_2_photo") ? 2 : 3;
        if($photo_msg){
            $photos=$photo_msg["photo"]; $photo_id=end($photos)["file_id"];
            setState($admin_id,"adm_add_speak_q_{$part_n}_text",$photo_id);
            sendMsg($chat_id,"✅ <b>Rasm saqlandi!</b>\n\nEndi bu rasm uchun <b>savol matnini</b> yuboring:",backPanel()); exit;
        } elseif($text && $text!="🔙 Panel"){
            sendMsg($chat_id,"📷 Iltimos, <b>rasm</b> yuboring (faqat rasm):"); exit;
        }
    }

    // ---- SPEAKING SAVOL QO'SHISH (Part 2/3 — STEP 2: savol matni) ----
    if(in_array($state,["adm_add_speak_q_2_text","adm_add_speak_q_3_text"])){
        $part_n = ($state=="adm_add_speak_q_2_text") ? 2 : 3;
        if($text && $text[0]!="/" && $text!="🔙 Panel"){
            $photo_id=$user["state_data"];
            db()->prepare("INSERT INTO speaking_questions(part,question,photo_file_id,active) VALUES(?,?,?,1)")->execute([$part_n,trim($text),$photo_id]);
            clearState($admin_id);
            $cnt=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=$part_n")->fetchColumn();
            sendMsg($chat_id,"✅ <b>Part $part_n savol qo'shildi!</b>\nJami: <b>$cnt ta</b>\n\n🛠 Panel:",panelKb()); exit;
        }
    }

    // ---- BROADCAST WAIT ----
    if($state=="adm_broadcast_wait" && $message){
        $mid_bc=$message["message_id"]; $chat_from=$chat_id;
        $total=db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        setState($admin_id,"adm_broadcast_confirm",$chat_from."|".$mid_bc);
        $kb=["inline_keyboard"=>[[["text"=>"✅ Ha, yuborish","callback_data"=>"adm_bc_confirm"],["text"=>"❌ Bekor","callback_data"=>"adm_bc_cancel"]]]];
        sendMsg($chat_id,"📣 <b>Yuqoridagi xabar <u>$total ta</u> foydalanuvchiga yuboriladi.</b>\n\nDavom etamizmi?",$kb); exit;
    }

    // ---- OTHER ADMIN STATES ----
    if($state=="adm_add_channel" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $ch=trim($text); if(strpos($ch,"@")!==0) $ch="@$ch";
        db()->prepare("INSERT OR IGNORE INTO channels(username) VALUES(?)")->execute([$ch]);
        clearState($admin_id); sendMsg($chat_id,"✅ Kanal qo'shildi: <b>$ch</b>\n\n🛠 Panel:",panelKb()); exit;
    }
    if($state=="adm_add_wallet" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $p=array_map("trim",explode("|",$text));
        if(count($p)>=3){
            db()->prepare("INSERT INTO wallets(name,number,holder,description,active) VALUES(?,?,?,?,1)")->execute([$p[0],$p[1],$p[2],$p[3]??""]);
            clearState($admin_id); sendMsg($chat_id,"✅ Hamyon qo'shildi: <b>".$p[0]."</b>\n\n🛠 Panel:",panelKb());
        } else { sendMsg($chat_id,"❌ Format: <code>Nomi | Raqam | Egasi | Izoh</code>"); }
        exit;
    }
    if($state=="adm_set_reward" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $v=preg_replace('/[^0-9.]/','',$text);
        if(is_numeric($v)&&$v>=0){ setSetting('ref_reward',$v); clearState($admin_id); sendMsg($chat_id,"✅ Mukofot: <b>".fmt($v)." ".getSetting('currency')."</b>\n\n🛠 Panel:",panelKb()); }
        else { sendMsg($chat_id,"❌ Faqat raqam."); } exit;
    }
    if($state=="adm_setprice" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $v=preg_replace('/[^0-9.]/','',$text);
        if(is_numeric($v)&&$v>0){ setSetting('premium_price',$v); clearState($admin_id); sendMsg($chat_id,"✅ Premium narxi: <b>".fmt($v)." ".getSetting('currency')."</b>\n\n🛠 Panel:",panelKb()); }
        else { sendMsg($chat_id,"❌ Faqat raqam."); } exit;
    }
    if($state=="adm_setpremiumchecks" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $v=(int)preg_replace('/[^0-9]/','',$text);
        if($v>0){ setSetting('premium_checks',$v); clearState($admin_id); sendMsg($chat_id,"✅ Premium tekshiruv: <b>$v ta</b>\n\n🛠 Panel:",panelKb()); }
        else { sendMsg($chat_id,"❌ Musbat raqam."); } exit;
    }
    if($state=="adm_setfreechecks" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $v=(int)preg_replace('/[^0-9]/','',$text);
        setSetting('free_checks',$v); clearState($admin_id);
        sendMsg($chat_id,"✅ Bepul tekshiruv: <b>$v ta</b>\n\n🛠 Panel:",panelKb()); exit;
    }
    if($state=="adm_offmsg" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        setSetting('off_message',$text); clearState($admin_id);
        sendMsg($chat_id,"✅ Off xabar saqlandi.\n\n🛠 Panel:",panelKb()); exit;
    }
    if($state=="adm_user_search" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $q_t=trim($text);
        if(is_numeric($q_t)){ $q=db()->prepare("SELECT * FROM users WHERE tg_id=?"); $q->execute([$q_t]); }
        else { $q=db()->prepare("SELECT * FROM users WHERE username=?"); $q->execute([ltrim($q_t,"@")]); }
        $found=$q->fetch(PDO::FETCH_ASSOC); clearState($admin_id);
        if(!$found){ sendMsg($chat_id,"❌ Topilmadi: <code>$q_t</code>"); exit; }
        $cur_s=getSetting('currency'); $prem_s=isPremium($found);
        $st=$prem_s?"⭐ Premium (".date("d.m.Y",$found["premium_expire"])." gacha)":"🆓 Free";
        $txt="👤 <b>Foydalanuvchi</b>\n\n🔹 Ism: ".htmlspecialchars($found["full_name"])."\n🔹 Username: ".($found["username"]?"@".$found["username"]:"—")."\n🔹 ID: <code>".$found["tg_id"]."</code>\n🔹 Tarif: $st\n💵 Balans: <b>".fmt($found["balance"])." $cur_s</b>\n✍️ Tekshiruvlar: ".$found["checks"]." ta\n👥 Taklif: ".$found["ref_count"]." ta\n📅 Qo'shilgan: ".date("d.m.Y",$found["joined_at"]);
        $kb=["inline_keyboard"=>[[["text"=>"💰 Pul qo'shish","callback_data"=>"adm_addbal_".$found["tg_id"]],["text"=>"💸 Pul ayirish","callback_data"=>"adm_subbal_".$found["tg_id"]]],[["text"=>($prem_s?"⬇️ Free ga o'tkazish":"⭐ Premium ga o'tkazish"),"callback_data"=>"adm_toggleprem_".$found["tg_id"]]],[["text"=>"🔙 Panel","callback_data"=>"adm_back"]]]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($state=="adm_addbal" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $uid=(int)$user["state_data"]; $v=preg_replace('/[^0-9.]/','',$text);
        if(is_numeric($v)&&$v>0){ db()->prepare("UPDATE users SET balance=balance+? WHERE tg_id=?")->execute([$v,$uid]); $cur_s=getSetting('currency'); $nb=getUser($uid)["balance"]; clearState($admin_id); sendMsg($chat_id,"✅ +".fmt($v)." $cur_s\n\n🛠 Panel:",panelKb()); sendMsg($uid,"✅ <b>Hisobingizga +".fmt($v)." $cur_s qo'shildi!</b>\n💵 Balans: ".fmt($nb)." $cur_s"); }
        else { sendMsg($chat_id,"❌ Noto'g'ri miqdor."); } exit;
    }
    if($state=="adm_subbal" && $text && $text[0]!="/" && $text!="🔙 Panel"){
        $uid=(int)$user["state_data"]; $v=preg_replace('/[^0-9.]/','',$text);
        if(is_numeric($v)&&$v>0){ $tg=getUser($uid); $nb=max(0,$tg["balance"]-(float)$v); db()->prepare("UPDATE users SET balance=? WHERE tg_id=?")->execute([$nb,$uid]); $cur_s=getSetting('currency'); clearState($admin_id); sendMsg($chat_id,"✅ Ayirildi. Yangi balans: ".fmt($nb)." $cur_s\n\n🛠 Panel:",panelKb()); }
        else { sendMsg($chat_id,"❌ Noto'g'ri miqdor."); } exit;
    }

    // ---- ADMIN PANEL MENU (keyboard handlers) ----
    if($text=="📢 Majburiy kanal"){
        $chs=getChannels(); $txt="📢 <b>Majburiy kanallar</b>\n\n"; $kb=["inline_keyboard"=>[]];
        if(empty($chs)) $txt.="Kanal yo'q.\n\n";
        foreach($chs as $i=>$c){ $txt.=($i+1).". $c\n"; $kb["inline_keyboard"][]=[["text"=>"🗑 $c","callback_data"=>"adm_delch_".urlencode($c)]]; }
        $txt.="\n➕ <code>@kanalnom</code> yuboring";
        setState($admin_id,"adm_add_channel"); $kb["inline_keyboard"][]=[["text"=>"🔙 Panel","callback_data"=>"adm_back"]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($text=="💳 Hamyon sozlash"){
        $ws=db()->query("SELECT * FROM wallets")->fetchAll(PDO::FETCH_ASSOC);
        $txt="💳 <b>Hamyon/Kartalar</b>\n\n"; $kb=["inline_keyboard"=>[]];
        if(empty($ws)) $txt.="Hamyon yo'q.\n\n";
        foreach($ws as $w){ $txt.=($w["active"]?"✅":"❌")." <b>".$w["name"]."</b>\n   ".$w["number"]." | ".$w["holder"]."\n\n"; $kb["inline_keyboard"][]=[["text"=>($w["active"]?"❌ O'chir":"✅ Yoq")." ".$w["name"],"callback_data"=>"adm_wtoggle_".$w["id"]],["text"=>"🗑","callback_data"=>"adm_wdel_".$w["id"]]]; }
        $txt.="➕ Yangi: <code>Nomi | Raqam | Egasi | Izoh</code> yuboring";
        setState($admin_id,"adm_add_wallet"); $kb["inline_keyboard"][]=[["text"=>"🔙 Panel","callback_data"=>"adm_back"]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($text=="👥 Referral sozlash"){ $rew=getSetting('ref_reward'); $cur_s=getSetting('currency'); setState($admin_id,"adm_set_reward"); sendMsg($chat_id,"👥 <b>Referral sozlash</b>\n\nHozirgi: <b>".fmt($rew)." $cur_s</b>\n\nYangi:\n<code>5000</code>",backPanel()); exit; }
    if($text=="💰 Narx sozlash"){
        $price=getSetting('premium_price'); $pch=getSetting('premium_checks'); $free=getSetting('free_checks'); $cur_s=getSetting('currency');
        $kb=["inline_keyboard"=>[[["text"=>"💵 Premium narxi","callback_data"=>"adm_set_price"]],[["text"=>"✍️ Premium tekshiruv soni","callback_data"=>"adm_set_premiumchecks"]],[["text"=>"🆓 Bepul tekshiruv soni","callback_data"=>"adm_set_freechecks"]],[["text"=>"💱 Valyuta","callback_data"=>"adm_set_currency"]],[["text"=>"🔙 Panel","callback_data"=>"adm_back"]]]];
        sendMsg($chat_id,"💰 <b>Narx sozlash</b>\n\nPremium: <b>".fmt($price)." $cur_s/oy</b>\nPremium tekshiruv: <b>$pch ta</b>\nBepul: <b>$free ta</b>",$kb); exit;
    }
    if($text=="🤖 Bot holati"){
        $active=getSetting('bot_active');
        $kb=["inline_keyboard"=>[[["text"=>($active=="1"?"❌ Botni o'chir":"✅ Botni yoq"),"callback_data"=>"adm_togglebot"]],[["text"=>"✏️ Off xabarni o'zgartir","callback_data"=>"adm_setoffmsg"]],[["text"=>"🔙 Panel","callback_data"=>"adm_back"]]]];
        sendMsg($chat_id,"🤖 <b>Bot holati</b>\n\nHozir: ".($active=="1"?"✅ Yoqiq":"❌ O'chiq")."\n\nOff xabar:\n<i>".htmlspecialchars(getSetting('off_message'))."</i>",$kb); exit;
    }
    if($text=="📊 Statistika"){
        $total=db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $prem_c=db()->query("SELECT COUNT(*) FROM users WHERE premium=1 AND premium_expire>".time())->fetchColumn();
        $pend=db()->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
        $app=db()->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM payments WHERE status='approved'")->fetch();
        $rej=db()->query("SELECT COUNT(*) FROM payments WHERE status='rejected'")->fetchColumn();
        $cur_s=getSetting('currency');
        sendMsg($chat_id,"📊 <b>Statistika</b>\n\n👥 Jami: <b>$total</b>\n⭐ Premium: <b>$prem_c</b>\n⏳ Kutilmoqda: <b>$pend</b>\n✅ Tasdiqlangan: <b>".$app[0]."</b>\n❌ Rad etilgan: <b>$rej</b>\n💰 Daromad: <b>".fmt($app[1])." $cur_s</b>",panelKb()); exit;
    }
    if($text=="👤 Foydalanuvchi"){ setState($admin_id,"adm_user_search"); sendMsg($chat_id,"👤 ID yoki @username yuboring:",backPanel()); exit; }
    if($text=="📣 Reklama yuborish"){
        clearState($admin_id); setState($admin_id,"adm_broadcast_wait");
        $total=db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        sendMsg($chat_id,"📣 <b>Reklama yuborish</b>\n\n👥 Foydalanuvchilar: <b>$total ta</b>\n\nXabarni yuboring (matn, rasm, video, har qanday):\n\n<i>⚠️ Yuborishdan oldin tasdiqlash so'raladi</i>",backPanel()); exit;
    }
    if($text=="🎤 Speaking savollar"){
        $p1=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=1 AND active=1")->fetchColumn();
        $p2=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=2 AND active=1")->fetchColumn();
        $p3=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=3 AND active=1")->fetchColumn();
        $kb=["inline_keyboard"=>[
            [["text"=>"📋 Part 1 savollar ($p1 ta)","callback_data"=>"adm_sq_list_1"]],
            [["text"=>"📋 Part 2 savollar ($p2 ta)","callback_data"=>"adm_sq_list_2"]],
            [["text"=>"📋 Part 3 savollar ($p3 ta)","callback_data"=>"adm_sq_list_3"]],
            [["text"=>"➕ Part 1 savol qo'sh","callback_data"=>"adm_sq_addpart_1"]],
            [["text"=>"➕ Part 2 savol qo'sh (rasm+savol)","callback_data"=>"adm_sq_addpart_2"]],
            [["text"=>"➕ Part 3 savol qo'sh (rasm+savol)","callback_data"=>"adm_sq_addpart_3"]],
            [["text"=>"🔙 Panel","callback_data"=>"adm_back"]],
        ]];
        sendMsg($chat_id,"🎤 <b>Speaking Savollar</b>\n\n🎯 Part 1 (faqat matn): <b>$p1 ta</b>\n📖 Part 2 (rasm+savol): <b>$p2 ta</b>\n💬 Part 3 (rasm+savol): <b>$p3 ta</b>",$kb); exit;
    }

    // ---- SPEAK QOIDA MENU ----
    if($text=="📜 Speak Qoida"){
        $r1=getSpeakingRule(1); $r2=getSpeakingRule(2); $r3=getSpeakingRule(3);
        $kb=["inline_keyboard"=>[
            [["text"=>"🎯 Part 1 qoida".($r1?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_1"]],
            [["text"=>"📖 Part 2 qoida".($r2?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_2"]],
            [["text"=>"💬 Part 3 qoida".($r3?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_3"]],
            [["text"=>"🔙 Panel","callback_data"=>"adm_back"]],
        ]];
        $txt="📜 <b>Speaking Qoidalar</b>\n\n"
            ."Part 1: ".($r1?"✅ Bor":"❌ Yo'q")."\n"
            ."Part 2: ".($r2?"✅ Bor":"❌ Yo'q")."\n"
            ."Part 3: ".($r3?"✅ Bor":"❌ Yo'q")."\n\n"
            ."<i>Har bir part uchun alohida qoida kiritiladi.</i>";
        sendMsg($chat_id,$txt,$kb); exit;
    }

    // ---- SPEAK QOIDA STATE HANDLERS ----
    if(in_array($state,["adm_speak_rule_1","adm_speak_rule_2","adm_speak_rule_3"]) && $message && $text!="🔙 Panel"){
        $part=(int)substr($state,-1);
        if($text && $text[0]!="/"){
            // Telegram entities saqlash (bold, italic, code va h.k.)
            $entities=$message["entities"]??[];
            $ent_json=json_encode($entities);
            db()->prepare("INSERT OR REPLACE INTO speaking_rules(part,content,entities_json) VALUES(?,?,?)")->execute([$part,$text,$ent_json]);
            clearState($admin_id);
            sendMsg($chat_id,"✅ <b>Part $part qoidasi saqlandi!</b>\n\n🔙 Panel:",panelKb()); exit;
        }
    }
}

/* ============================================================
   TO'LOV STATE HANDLERS
============================================================ */
if($message && $state=="wait_amount" && $text && $text[0]!="/" && $text!="🔙 Orqaga"){
    $wid=(int)$user["state_data"]; $amount=preg_replace('/[^0-9.]/','',$text);
    if(!is_numeric($amount)||(float)$amount<=0){ sendMsg($chat_id,"❌ Noto'g'ri miqdor:\n<code>50000</code>"); exit; }
    setState($user_id,"wait_check",$wid."|".$amount); $cur_s=getSetting('currency');
    sendMsg($chat_id,"📸 <b>Chek yuboring</b>\n\nMiqdor: <b>".fmt($amount)." $cur_s</b>\n\n📷 To'lov cheki rasmini yuboring:",backKb()); exit;
}
if($message && $state=="wait_check" && $photo_msg){
    $pts=explode("|",$user["state_data"],2); $wid=(int)$pts[0]; $amount=(float)$pts[1]; $cur_s=getSetting('currency');
    $photos=$photo_msg["photo"]; $photo_id=end($photos)["file_id"];
    db()->prepare("INSERT INTO payments(tg_id,amount,wallet_id,check_file,status,created) VALUES(?,?,?,?,'pending',?)")->execute([$user_id,$amount,$wid,$photo_id,time()]);
    $pid=db()->lastInsertId(); clearState($user_id);
    sendMsg($chat_id,"✅ <b>To'lov yuborildi!</b>\n\n💰 Miqdor: <b>".fmt($amount)." $cur_s</b>\n🔖 #$pid\n⏳ Admin tasdiqlashini kuting.",mainKb($user_id==$admin_id));
    $ulink=$user["username"]?"@".$user["username"]:"—";
    $caption="💳 <b>Yangi to'lov #$pid</b>\n\n👤 ".htmlspecialchars($user["full_name"])."\n🔗 $ulink\n🆔 <code>$user_id</code>\n💰 <b>".fmt($amount)." $cur_s</b>\n📅 ".date("d.m.Y H:i");
    $kb_a=["inline_keyboard"=>[[["text"=>"👁 Profil","url"=>"tg://user?id=$user_id"]],[["text"=>"✅ Tasdiqlash","callback_data"=>"approve_pay_$pid"],["text"=>"❌ Rad etish","callback_data"=>"reject_pay_$pid"]]]];
    sendPhoto($admin_id,$photo_id,$caption,$kb_a); exit;
}
if($message && $state=="wait_check" && $text && $text[0]!="/"){ sendMsg($chat_id,"📷 Iltimos, chekni <b>rasm</b> sifatida yuboring.",backKb()); exit; }

/* ============================================================
   WRITING STATE HANDLERS
============================================================ */
if($message && $state=="wait_writing_q" && $text && $text[0]!="/" && $text!="🔙 Orqaga"){
    $task_code=$user["state_data"]; $info=getTaskInfo($task_code);
    if(!$info){ clearState($user_id); sendMsg($chat_id,"❌ Xatolik.",mainKb($user_id==$admin_id)); exit; }
    $question=trim($text);
    setState($user_id,"check_writing",$task_code."|".base64_encode($question));
    sendMsg($chat_id,"✅ <b>Savol qabul qilindi!</b>\n\n📝 <b>".$info["name"]."</b>\n🔤 Uslub: <b>".$info["register"]."</b>\n\n📋 Savol:\n<i>".htmlspecialchars(mb_substr($question,0,300)).(mb_strlen($question)>300?"...":"")."</i>\n\n✍️ Endi writing yuboring:\n└ Min: <b>".$info["min_words"]." so'z</b>\n└ Max: <b>".$info["max_words"]." so'z</b>",backKb()); exit;
}
if($message && $state=="check_writing" && $text && strlen($text)>5 && $text[0]!="/" && $text!="🔙 Orqaga"){
    $parts=explode("|",$user["state_data"],2); $task_code=$parts[0]??"2"; $question=base64_decode($parts[1]??"");
    $info=getTaskInfo($task_code);
    if(!$info){ clearState($user_id); sendMsg($chat_id,"❌ Xatolik.",mainKb($user_id==$admin_id)); exit; }
    $words=str_word_count($text);
    if($words>$info["max_words"]){ sendMsg($chat_id,"❌ Maksimal <b>".$info["max_words"]." so'z</b>. Siz <b>$words so'z</b> yubordingiz."); exit; }
    if($words<$info["min_words"]){ sendMsg($chat_id,"❌ Minimal <b>".$info["min_words"]." so'z</b>. Siz <b>$words so'z</b> yubordingiz."); exit; }
    $prem=isPremium($user);
    if(!$prem && $user["checks"]<=0){ clearState($user_id); sendMsg($chat_id,"❌ <b>Bepul tekshiruvlar tugadi!</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
    sendMsg($chat_id,"⏳ <b>Tekshirilmoqda...</b>\n\n📝 ".$info["name"]."\n\nGroq AI CEFR standartida tahlil qilmoqda...\n10-20 soniya kuting.");
    $result=$prem?aiWritingPremium($text):aiWritingFree($text);
    $header="📊 <b>".$info["name"]." Natijasi</b>\n📋 Savol: <i>".htmlspecialchars(mb_substr($question,0,120)).(mb_strlen($question)>120?"...":"")."</i>\n✍️ So'zlar: <b>$words ta</b>\n\n";
    $footer=$prem?"":"\n\n━━━━━━━━━━━━━━━━━\n🌟 <b>Premium orqali to'liq, yaxshilangan javob oling!</b>\n✅ Batafsil CEFR tahlil\n✅ Xatolar tuzatish\n✅ C1 namuna javob\n✅ Yaxshilangan versiya";
    sendMsg($chat_id,$header.$result.$footer);
    if(!$prem) sendMsg($chat_id,"💎 <b>To'liq tahlil + C1 namuna + Yaxshilangan versiya</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium olish","callback_data"=>"show_premium"]]]]);
    db()->prepare("UPDATE users SET checks=checks-1 WHERE tg_id=?")->execute([$user_id]);
    $left=$user["checks"]-1;
    if(!$prem){
        if($left<=0) sendMsg($chat_id,"⚠️ <b>Bepul tekshiruvlar tugadi!</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]);
        elseif($left==1) sendMsg($chat_id,"⚠️ Sizda faqat <b>1 ta</b> bepul tekshiruv qoldi.");
    }
    clearState($user_id); exit;
}

/* ============================================================
   SPEAKING STATE HANDLERS
   State format:
   Part 1: "sp1_prep|q_idx|questions_json|transcripts_b64"  — prep timer
           "sp1_ans|q_idx|questions_json|transcripts_b64"   — answer audio wait
           (transcripts_b64 = base64(json_encode([{q,a},...}])))
           3 javob yig'iladi — oxirida 1ta AI tahlil, 1ta ball
   Part 2: "sp2_prep|q_id"                 — prep timer
           "sp2_ans|q_id"                  — answer audio wait
   Part 3: "sp3_prep|q_id"
           "sp3_ans|q_id"
============================================================ */

// ---- PART 1 PREP TIMER ----
if($message && $state=="sp1_prep"){
    $sd=$user["state_data"];
    $is_paused=(strpos($sd,"paused|")===0);

    if($text=="⏹ To'xtatish"){
        setState($user_id,"stopped","");
        sendMsg($chat_id,"⏹ <b>To'xtatildi.</b>\n\n🏠 Bosh menyuga qaytdingiz.",mainKb($user_id==$admin_id));
        clearState($user_id); exit;
    }
    if($text=="⏸ Pauza"){
        if(!$is_paused) setState($user_id,"sp1_prep","paused|".$sd);
        sendMsg($chat_id,"⏸ <b>Pauza qilindi.</b>\n\n▶️ Davom etish uchun <b>Davom</b> tugmasini bosing.",speakingKb());
        exit;
    }
    if($text=="▶️ Davom" && $is_paused){
        // Faqat paused holatida davom etish ishlaydi
        $sd=substr($sd,7);
        setState($user_id,"sp1_prep",$sd);
        $sparts=explode("|",$sd,4);
        $q_idx=(int)($sparts[1]??0);
        $questions=json_decode($sparts[2]??"[]",true);
        $q_obj=$questions[$q_idx]??null;
        $q_clean=$q_obj?preg_replace('/^#\S+\s*/','',trim($q_obj["question"])):"";
        sendMsg($chat_id,"▶️ <b>Davom etilyapti!</b>\n\n❓ <b>".htmlspecialchars($q_clean)."</b>\n\n⏳ Tayyorlanish sanovi qayta boshlanmoqda...",noKb());
        $tr=sendTimerPrep($chat_id,$user_id,10,"sp1_prep"); if($tr!=='done') exit;
        sendMsg($chat_id,"🎤 <b>Gapiring!</b> Ovozli xabar yuboring.",stopOnlyKb());
        $tr=sendTimerAnswer($chat_id,$user_id,30,"sp1_prep"); if($tr!=='done') exit;
        setState($user_id,"sp1_ans","|".$q_idx."|".($sparts[2]??"[]")."|".($sparts[3]??base64_encode("[]")));
        exit;
    }
    // Timer aktiv ishlayotganda kelgan boshqa xabarlar — e'tiborsiz
    exit;
}

// ---- PART 1 ANSWER WAIT ----
if($message && $state=="sp1_ans" && ($voice_msg||$audio_msg)){
    $sd=$user["state_data"];
    if(strpos($sd,"paused|")===0) $sd=substr($sd,7);
    // State format: |q_idx|questions_json|transcripts_b64
    $sparts=explode("|",$sd,4);
    $q_idx=(int)($sparts[1]??0);
    $questions_json=$sparts[2]??"[]";
    $transcripts_b64=$sparts[3]??base64_encode("[]");
    $questions=json_decode($questions_json,true);
    $transcripts=json_decode(base64_decode($transcripts_b64),true);
    if(!is_array($transcripts)) $transcripts=[];

    $file_obj=$voice_msg?$voice_msg["voice"]:$audio_msg["audio"];
    $file_id=$file_obj["file_id"];

    // Timer (sp1_prep)ni to'xtatish uchun state ni vaqtincha o'zgartir
    setState($user_id,"sp1_ans_proc","|".$q_idx."|".$questions_json."|".$transcripts_b64);
    sleep(5);
    sendMsg($chat_id,"🎧 <b>Javob ".($q_idx+1)."/".count($questions)." qabul qilindi!</b>\n⏳ Matnga aylantirilmoqda...");
    $file_url=getFileUrl($file_id);
    if(!$file_url){ sendMsg($chat_id,"❌ Faylni yuklab bo'lmadi. Qaytadan yuboring."); exit; }
    $transcript=transcribeAudio($file_url);
    if(!$transcript||strlen(trim($transcript))<2){
        sendMsg($chat_id,"❌ Audioda matn aniqlanmadi.\nInglizcha gapirib, qaytadan yuboring."); exit;
    }

    $q_obj=$questions[$q_idx]??null;
    $q_clean=$q_obj?preg_replace('/^#\S+\s*/','',trim($q_obj["question"])):"";

    // Transcriptni saqlash
    $transcripts[$q_idx]=["q"=>$q_clean,"a"=>$transcript];
    $new_transcripts_b64=base64_encode(json_encode($transcripts));

    $next_idx=$q_idx+1;
    $total=count($questions);

    if($next_idx < $total){
        // Keyingi savol bor — faqat transcriptni saqlang, AI yo'q
        $next_q=$questions[$next_idx];
        $next_clean=preg_replace('/^#\S+\s*/','',trim($next_q["question"]));

        sendMsg($chat_id,"✅ <b>".($q_idx+1)."-javob qabul qilindi!</b>\n\n📝 <i>".htmlspecialchars(mb_substr($transcript,0,200))."</i>");

        setState($user_id,"sp1_prep","|".$next_idx."|".$questions_json."|".$new_transcripts_b64);
        sendMsg($chat_id,
            "⏭️ <b>Keyingi savol — ".($next_idx+1)."/$total</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."❓ <b>".htmlspecialchars($next_clean)."</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."⏳ <b>10 soniya</b> tayyorlanish boshlanmoqda...",
            noKb());

        $tr=sendTimerPrep($chat_id,$user_id,10,"sp1_prep"); if($tr!=='done') exit;
        sendMsg($chat_id,"🎤 <b>Gapiring!</b> Ovozli xabar yuboring.",stopOnlyKb());
        $tr=sendTimerAnswer($chat_id,$user_id,30,"sp1_prep"); if($tr!=='done') exit;
        setState($user_id,"sp1_ans","|".$next_idx."|".$questions_json."|".$new_transcripts_b64);

    } else {
        // Oxirgi (3-chi) javob — endi hammasi birga AI tekshiruvi
        sendMsg($chat_id,"✅ <b>3-javob qabul qilindi!</b>\n\n📝 <i>".htmlspecialchars(mb_substr($transcript,0,200))."</i>\n\n🤖 <b>Barcha 3 ta javob birga tahlil qilinmoqda...</b>\n⏳ 15-30 soniya kuting...");

        $prem=isPremium($user);

        // qa_pairs yig'ish
        $qa_pairs=[];
        for($i=0;$i<$total;$i++){
            $qa_pairs[]=["q"=>($transcripts[$i]["q"]??($questions[$i]?preg_replace('/^#\S+\s*/','',trim($questions[$i]["question"])):"")),"a"=>($transcripts[$i]["a"]??'')];
        }
        // Oxirgi javobni ham qo'shamiz
        $qa_pairs[$q_idx]=["q"=>$q_clean,"a"=>$transcript];

        $result=$prem?aiSpeakingPart1Premium($qa_pairs):aiSpeakingPart1Free($qa_pairs);

        // Sarlavha
        $header="🎙️ <b>SPEAKING PART 1 — Yakuniy Natija</b>\n\n";
        for($i=0;$i<count($qa_pairs);$i++){
            $header.="❓ <i>Q".($i+1).": ".htmlspecialchars(mb_substr($qa_pairs[$i]["q"],0,60))."</i>\n";
        }
        $header.="\n";

        $footer=$prem?"":"\n\n━━━━━━━━━━━━━━━━━\n🌟 <b>Premium orqali to'liq tahlil oling!</b>\n✅ Har 3 javob batafsil baho\n✅ Xatolar + namuna javoblar";
        sendMsg($chat_id,$header.$result.$footer);
        if(!$prem) sendMsg($chat_id,"💎 <b>Premium — To'liq Speaking tahlili</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium olish","callback_data"=>"show_premium"]]]]);

        // Faqat 1 ta ball ayiriladi — Part 1 tugagandan keyin
        db()->prepare("UPDATE users SET checks=checks-1 WHERE tg_id=?")->execute([$user_id]);
        $left=getUser($user_id)["checks"];
        if(!$prem){
            if($left<=0) sendMsg($chat_id,"⚠️ <b>Bepul tekshiruvlar tugadi!</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]);
            elseif($left==1) sendMsg($chat_id,"⚠️ Sizda faqat <b>1 ta</b> bepul tekshiruv qoldi.");
        }

        clearState($user_id);
        sendMsg($chat_id,"🏆 <b>Part 1 yakunlandi!</b>\n✅ Barcha 3 ta savol 1 ta imkoniyat ichida baholandi.",mainKb($user_id==$admin_id));
    }
    exit;
}
// Part 1 ans — text handling (faqat to'xtatish)
if($message && $state=="sp1_ans" && !$voice_msg && !$audio_msg){
    if($text!="⏹ To'xtatish") sendMsg($chat_id,"🎤 Iltimos <b>ovozli xabar</b> yuboring!",stopOnlyKb());
    exit;
}

// ---- PART 2 PREP (eski state — to'xtatish) ----
if($message && $state=="sp2_prep"){
    clearState($user_id);
    sendMsg($chat_id,"🏠 <b>Bosh menyu</b>",mainKb($user_id==$admin_id)); exit;
}


// ---- PART 2 TIMER (ishlayapti — faqat paused/stopped qabul qiladi) ----
if($message && $state=="sp2_timer"){
    $q_id=(int)$user["state_data"];
    if($text=="⏹ To'xtatish"){
        setState($user_id,"stopped","");
        sendMsg($chat_id,"⏹ <b>To'xtatildi.</b>\n\n🏠 Bosh menyuga qaytdingiz.",mainKb($user_id==$admin_id));
        clearState($user_id); exit;
    }
    if($text=="⏸ Pauza"){
        setState($user_id,"sp2_timer","paused|".$q_id);
        sendMsg($chat_id,"⏸ <b>Pauza qilindi.</b>\n\n▶️ Davom etish uchun <b>Davom</b> tugmasini bosing.",speakingKb());
        exit;
    }
    // Boshqa xabarlar — e'tiborsiz (timer ishlayapti)
    exit;
}

// ---- PART 2 ANSWER WAIT ----
if($message && $state=="sp2_ans" && ($voice_msg||$audio_msg)){
    $sd=$user["state_data"];
    if(strpos($sd,"paused|")===0) $sd=substr($sd,7);
    $q_id=(int)$sd;
    // sp2_timer ni to'xtatish uchun state o'zgartiramiz — timer stopped ko'radi
    setState($user_id,"sp2_ans_proc",(string)$q_id);
    $q_row=db()->prepare("SELECT * FROM speaking_questions WHERE id=?"); $q_row->execute([$q_id]); $q_obj=$q_row->fetch(PDO::FETCH_ASSOC);
    $q_clean=$q_obj?preg_replace('/^#\S+\s*/','',trim($q_obj["question"])):"";

    $file_obj=$voice_msg?$voice_msg["voice"]:$audio_msg["audio"];
    // 5 soniya kut (vaqtdan oldin javob berilgan bo'lishi mumkin)
    sleep(5);
    sendMsg($chat_id,"🎧 <b>Javob qabul qilindi!</b>\n⏳ Matnga aylantirilmoqda...",mainKb($user_id==$admin_id));
    $file_url=getFileUrl($file_obj["file_id"]);
    if(!$file_url){ sendMsg($chat_id,"❌ Fayl yuklanmadi. Qaytadan yuboring."); exit; }
    $transcript=transcribeAudio($file_url);
    if(!$transcript||strlen(trim($transcript))<2){ sendMsg($chat_id,"❌ Matn aniqlanmadi. Qaytadan yuboring."); exit; }

    $prem=isPremium($user);
    sendMsg($chat_id,"📝 <b>Transcription:</b>\n<i>".htmlspecialchars(mb_substr($transcript,0,400))."</i>\n\n⏳ AI tahlil qilmoqda...");
    $result=$prem?aiSpeakingPremium($transcript,$q_clean):aiSpeakingFree($transcript,$q_clean);
    $header="🎙️ <b>Part 2 — Long Turn Natijasi</b>\n❓ <i>".htmlspecialchars(mb_substr($q_clean,0,80))."</i>\n\n";
    $footer=$prem?"":"\n\n━━━━━━━━━━━━━━━━━\n🌟 <b>Premium orqali to'liq AI tahlil!</b>";
    sendMsg($chat_id,$header.$result.$footer);
    if(!$prem) sendMsg($chat_id,"💎 <b>Premium — To'liq tahlil</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium olish","callback_data"=>"show_premium"]]]]);
    db()->prepare("UPDATE users SET checks=checks-1 WHERE tg_id=?")->execute([$user_id]);
    clearState($user_id);
    sendMsg($chat_id,"✅ <b>Part 2 yakunlandi!</b>",mainKb($user_id==$admin_id)); exit;
}
if($message && $state=="sp2_ans" && !$voice_msg && !$audio_msg){
    // Faqat to'xtatish ishlaydi javob vaqtida
    if($text!="⏹ To'xtatish") sendMsg($chat_id,"🎤 Iltimos <b>ovozli xabar</b> yuboring!",stopOnlyKb()); exit;
}

// ---- PART 3 PREP (eski state — to'xtatish) ----
if($message && $state=="sp3_prep"){
    clearState($user_id);
    sendMsg($chat_id,"🏠 <b>Bosh menyu</b>",mainKb($user_id==$admin_id)); exit;
}


// ---- PART 3 TIMER (ishlayapti — faqat paused/stopped qabul qiladi) ----
if($message && $state=="sp3_timer"){
    $q_id=(int)$user["state_data"];
    if($text=="⏹ To'xtatish"){
        setState($user_id,"stopped","");
        sendMsg($chat_id,"⏹ <b>To'xtatildi.</b>\n\n🏠 Bosh menyuga qaytdingiz.",mainKb($user_id==$admin_id));
        clearState($user_id); exit;
    }
    if($text=="⏸ Pauza"){
        setState($user_id,"sp3_timer","paused|".$q_id);
        sendMsg($chat_id,"⏸ <b>Pauza qilindi.</b>\n\n▶️ Davom etish uchun <b>Davom</b> tugmasini bosing.",speakingKb());
        exit;
    }
    exit;
}

// ---- PART 3 ANSWER WAIT ----
if($message && $state=="sp3_ans" && ($voice_msg||$audio_msg)){
    $sd=$user["state_data"];
    if(strpos($sd,"paused|")===0) $sd=substr($sd,7);
    $q_id=(int)$sd;
    setState($user_id,"sp3_ans_proc",(string)$q_id);
    $q_row=db()->prepare("SELECT * FROM speaking_questions WHERE id=?"); $q_row->execute([$q_id]); $q_obj=$q_row->fetch(PDO::FETCH_ASSOC);
    $q_clean=$q_obj?preg_replace('/^#\S+\s*/','',trim($q_obj["question"])):"";

    $file_obj=$voice_msg?$voice_msg["voice"]:$audio_msg["audio"];
    sleep(5);
    sendMsg($chat_id,"🎧 <b>Javob qabul qilindi!</b>\n⏳ Matnga aylantirilmoqda...",mainKb($user_id==$admin_id));
    $file_url=getFileUrl($file_obj["file_id"]);
    if(!$file_url){ sendMsg($chat_id,"❌ Fayl yuklanmadi. Qaytadan yuboring."); exit; }
    $transcript=transcribeAudio($file_url);
    if(!$transcript||strlen(trim($transcript))<2){ sendMsg($chat_id,"❌ Matn aniqlanmadi. Qaytadan yuboring."); exit; }

    $prem=isPremium($user);
    sendMsg($chat_id,"📝 <b>Transcription:</b>\n<i>".htmlspecialchars(mb_substr($transcript,0,400))."</i>\n\n⏳ AI tahlil qilmoqda...");
    $result=$prem?aiSpeakingPremium($transcript,$q_clean):aiSpeakingFree($transcript,$q_clean);
    $header="🎙️ <b>Part 3 — Discussion Natijasi</b>\n❓ <i>".htmlspecialchars(mb_substr($q_clean,0,80))."</i>\n\n";
    $footer=$prem?"":"\n\n━━━━━━━━━━━━━━━━━\n🌟 <b>Premium orqali to'liq AI tahlil!</b>";
    sendMsg($chat_id,$header.$result.$footer);
    if(!$prem) sendMsg($chat_id,"💎 <b>Premium — To'liq tahlil</b>",["inline_keyboard"=>[[["text"=>"⭐ Premium olish","callback_data"=>"show_premium"]]]]);
    db()->prepare("UPDATE users SET checks=checks-1 WHERE tg_id=?")->execute([$user_id]);
    clearState($user_id);
    sendMsg($chat_id,"✅ <b>Part 3 yakunlandi!</b>",mainKb($user_id==$admin_id)); exit;
}
if($message && $state=="sp3_ans" && !$voice_msg && !$audio_msg){
    if($text!="⏹ To'xtatish") sendMsg($chat_id,"🎤 Iltimos <b>ovozli xabar</b> yuboring!",stopOnlyKb()); exit;
}

/* ============================================================
   MAIN MENU HANDLERS
============================================================ */

// ---- 👤 Hisobim ----
if($message && $text=="👤 Hisobim"){
    $cur_s=getSetting('currency'); $prem=isPremium($user); $pch=(int)getSetting('premium_checks');
    $name_esc=htmlspecialchars($user["full_name"]);
    $uname_txt=$user["username"]?"@".$user["username"]:"—";

    if($prem){
        $days_left=max(0,ceil(($user["premium_expire"]-time())/86400));
        $bar_fill=min(10,(int)($user["checks"]/$pch*10));
        $bar=str_repeat("🟨",$bar_fill).str_repeat("⬜",10-$bar_fill);
        $status_block="╔══════════════════╗\n"
            ."║  ⭐ <b>PREMIUM</b> MEMBER  ║\n"
            ."╚══════════════════╝\n\n"
            ."📅 Muddat: <b>".date("d.m.Y",$user["premium_expire"])."</b> (<b>$days_left kun</b> qoldi)\n"
            ."✍️ Tekshiruvlar: <b>".$user["checks"]." / $pch</b>\n"
            .$bar;
    } else {
        $left=$user["checks"];
        $bar=str_repeat("🟩",max(0,$left)).str_repeat("⬜",max(0,3-$left));
        $status_block="▸ Tarif: 🆓 <b>Free</b>\n"
            ."▸ Tekshiruvlar: <b>$left ta</b> qoldi $bar";
    }

    $txt="━━━━━━━━━━━━━━━━━━━━━\n"
        ."👤 <b>MENING HISOBIM</b>\n"
        ."━━━━━━━━━━━━━━━━━━━━━\n\n"
        ."🪪 <b>$name_esc</b>\n"
        ."🔗 $uname_txt\n"
        ."🆔 <code>".$user["tg_id"]."</code>\n\n"
        ."$status_block\n\n"
        ."━━━━━━━━━━━━━━━━━━━━━\n"
        ."💵 Balans: <b>".fmt($user["balance"])." $cur_s</b>\n"
        ."👥 Taklif qilingan: <b>".$user["ref_count"]." kishi</b>\n"
        ."💸 Referraldan: <b>".fmt($user["ref_earned"])." $cur_s</b>\n"
        ."📅 Qo'shilgan: <b>".date("d.m.Y",$user["joined_at"])."</b>\n"
        ."━━━━━━━━━━━━━━━━━━━━━";

    $kb_rows=[];
    if(!$prem) $kb_rows[]=[["text"=>"⭐ Premium olish","callback_data"=>"show_premium"]];
    $kb_rows[]=[["text"=>"💳 Hisob to'ldirish","callback_data"=>"topup_start"]];
    $kb=["inline_keyboard"=>$kb_rows];

    $photo_id=getUserPhotoId($user_id);
    if($photo_id){ $res=sendPhoto($chat_id,$photo_id,$txt,$kb); if(empty($res["ok"])) sendMsg($chat_id,$txt,$kb); }
    else { global $default_photo; $res=sendPhoto($chat_id,$default_photo,$txt,$kb); if(empty($res["ok"])) sendMsg($chat_id,$txt,$kb); }
    exit;
}

// ---- WRITING 📝 ----
if($message && $text=="WRITING 📝"){
    $prem=isPremium($user); $left=$user["checks"]; $pch=(int)getSetting('premium_checks');
    clearState($user_id);
    $txt="WRITING 📝 <b>Tekshirish</b>\n\n"
        .($prem?"⭐ Premium — <b>$left / $pch</b> ta tekshiruv":"🆓 Qolgan: <b>$left ta</b>")."\n\n"
        ."📌 <b>Qaysi taskni tekshirmoqchisiz?</b>\n\n"
        ."✉️ <b>Task 1.1</b> — Informal Letter · max 50 so'z\n"
        ."📄 <b>Task 1.2</b> — Formal Letter · 120–150 so'z\n"
        ."📝 <b>Task 2</b>   — Formal Essay · 180–200 so'z";
    sendMsg($chat_id,$txt,writingTaskKb()); exit;
}

// ---- SPEAKING 🎙️ ----
if($message && $text=="SPEAKING 🎙️"){
    $prem=isPremium($user); $left=$user["checks"];
    clearState($user_id);
    $txt="SPEAKING 🎙️ <b>Tekshirish</b>\n\n"
        .($prem?"⭐ Premium — <b>$left ta</b> tekshiruv":"🆓 Qolgan: <b>$left ta</b>")."\n\n"
        ."🎯 <b>Qaysi Part?</b>\n\n"
        ."🎯 <b>Part 1</b> — Interview\n"
        ."   3 ta savol | 10s tayyorlanish + 30s javob\n\n"
        ."📖 <b>Part 2</b> — Long Turn\n"
        ."   Rasm + savol | 60s tayyorlanish + 2 daq javob\n\n"
        ."💬 <b>Part 3</b> — Discussion\n"
        ."   Rasm + savol | 60s tayyorlanish + 2 daq javob\n\n"
        ."🎤 <b>Ovozli xabar</b> yuborasiz →";
    sendMsg($chat_id,$txt,speakingMenuKb()); exit;
}

// ---- ⭐ Premium ----
if($message && $text=="⭐ Premium"){
    $price=(float)getSetting('premium_price'); $pch=(int)getSetting('premium_checks'); $cur_s=getSetting('currency'); $prem=isPremium($user);
    if($prem){
        sendMsg($chat_id,"⭐ <b>Siz hozir Premium holatdasiz!</b>\n\n⏳ Muddat: ".date("d.m.Y",$user["premium_expire"])." gacha\n✍️ Qolgan: <b>".$user["checks"]."</b> ta tekshiruv\n\n🚀 Premium imkoniyatlaringizdan to'liq foydalaning!",mainKb($user_id==$admin_id)); exit;
    }
    $has=($user["balance"]>=$price);
    $txt="👑 <b>CEFR MULTILEVEL PREMIUM AFZALLIKLARI</b>\n\n"
        ."1️⃣ 🎯 CEFR darajaga mos baholash (A1 → C1)\n"
        ."2️⃣ 🧠 AI Speaking Examiner — haqiqiy imtihonchi kabi\n"
        ."3️⃣ 🎤 Real Speaking Simulator — Part 1, 2, 3\n"
        ."4️⃣ ⏱️ Real Timer Mode — vaqt bilan mashq\n"
        ."5️⃣ 🔁 Dynamic Follow-up — javobingizga mos savollar\n"
        ."6️⃣ 🧠 Smart Interruption — \"Why?\", \"Explain?\"\n"
        ."7️⃣ 📊 Full Band Score — Fluency, Vocab, Grammar\n"
        ."8️⃣ 😬 Filler Word Detector — \"um\", \"uh\" aniqlash\n"
        ."9️⃣ 🎤 Speech Analytics — tezlik, pauza tahlili\n"
        ."🔟 📚 Vocabulary Analyzer — repetition tekshiruvi\n"
        ."1️⃣1️⃣ 🔥 Grammar Check Pro — xatolar + tuzatish\n"
        ."1️⃣2️⃣ ✍️ Writing Evaluation — CEFR asosida\n"
        ."1️⃣3️⃣ 📈 Progress Tracking — statistika va history\n"
        ."1️⃣4️⃣ 📅 Personal Study Plan — 7 kunlik reja\n"
        ."1️⃣5️⃣ 🏆 Full Report Export — natijalarni yuklab olish\n\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n"
        ."💰 Narxi: <b>".fmt($price)." $cur_s / oy</b>\n"
        ."✍️ ".fmt($pch)." ta tekshiruv/oy\n"
        ."💵 Balansingiz: <b>".fmt($user["balance"])." $cur_s</b>\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n\n"
        ."<i>🚀 CEFR asosidagi AI yordamida Speaking &amp; Writing'ni professional darajada oshiring!</i>\n\n"
        .($has?"✅ <b>Sotib olishingiz mumkin!</b>":"❌ Mablag' yetarli emas. Hisobni to'ldiring.");
    $kb=["inline_keyboard"=>[]];
    if($has) $kb["inline_keyboard"][]=[["text"=>"✅ Premium Sotib Olish","callback_data"=>"confirm_premium"]];
    $kb["inline_keyboard"][]=[["text"=>"💳 Hisob To'ldirish","callback_data"=>"topup_start"]];
    sendMsg($chat_id,$txt,$kb); exit;
}

// ---- 💰 Pul ishlash ----
if($message && $text=="💰 Pul ishlash"){
    $cur_s=getSetting('currency'); $rew=(float)getSetting('ref_reward');
    $link="https://t.me/{$bot_username}?start=ref_".$user["tg_id"];
    $count=$user["ref_count"]; $earned=(float)$user["ref_earned"];

    // Progress bar
    $bar_fill=min(10,$count);
    $bar=str_repeat("💎",$bar_fill).str_repeat("⬜",10-$bar_fill);

    // Rank
    if($count==0)       $rank="🌱 Yangi Boshlovchi";
    elseif($count<3)    $rank="🔥 Faol Ta'rif";
    elseif($count<7)    $rank="🚀 Kuchli Taklif";
    elseif($count<15)   $rank="💫 Super Referrer";
    else                $rank="👑 LEGEND";

    $txt=
        "╔══════════════════════╗\n"
        ."   💰  <b>PUL ISHLASH</b>  💰   \n"
        ."╚══════════════════════╝\n\n"
        ."<i>«Do'stingizni taklif qiling — ikkalangiz ham yutasiz!»</i>\n\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n"
        ."💵 <b>1 taklif = ".fmt($rew)." $cur_s</b> hisobingizga\n"
        ."⚡️ Cheksiz taklif — cheksiz daromad!\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n\n"
        ."🏅 <b>Darajangiz:</b> $rank\n\n"
        ."📊 <b>Statistika:</b>\n"
        ."👥 Taklif qilinganlar:  <b>$count kishi</b>\n"
        ."💸 Jami ishlanganlar:  <b>".fmt($earned)." $cur_s</b>\n\n"
        ."📈 <b>Taraqqiyot:</b>\n"
        ."$bar  <b>$count / 10</b>\n\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n"
        ."🔗 <b>Sizning havolangiz:</b>\n"
        ."<code>$link</code>\n"
        ."━━━━━━━━━━━━━━━━━━━━━━\n\n"
        ."👇 <i>Quyidagi tugma orqali do'stlaringizga yuboring!</i>";

    $share_text="🎓 CEFR darajangizni biling!\n\n"
        ."✍️ Writing + 🎙️ Speaking tekshirish\n"
        ."🤖 AI asosida — bepul boshlang!\n\n"
        ."👉 Havola: $link";

    $kb=["inline_keyboard"=>[
        [["text"=>"🚀 Do'stlarga Ulashish","url"=>"https://t.me/share/url?url=".urlencode($link)."&text=".urlencode($share_text)]],
        [["text"=>"📋 Havolani Nusxalash","callback_data"=>"copy_ref_link"]],
    ]];
    sendMsg($chat_id,$txt,$kb); exit;
}

/* ============================================================
   CALLBACK HANDLER
============================================================ */
if($callback){
    answerCb($callback["id"]);
    $mid=$callback["message"]["message_id"];
    $ia=($user_id==$admin_id);

    if($cdata=="check_sub"){
        if(checkJoin($user_id)){ sendMsg($chat_id,"✅ Obuna tasdiqlandi!",mainKb($user_id==$admin_id)); }
        else {
            $chs=getChannels(); $kb=["inline_keyboard"=>[]];
            foreach($chs as $ch) $kb["inline_keyboard"][]=[["text"=>"📢 ".ltrim($ch,"@"),"url"=>"https://t.me/".ltrim($ch,"@")]];
            $kb["inline_keyboard"][]=[["text"=>"✅ Tekshirish","callback_data"=>"check_sub"]];
            sendMsg($chat_id,"❌ Hali obuna bo'lmadingiz.",$kb);
        } exit;
    }
    if($cdata=="go_home"){ clearState($user_id); sendMsg($chat_id,"🏠 Bosh menyu",mainKb($user_id==$admin_id)); exit; }

    // ---- PUL ISHLASH — havola nusxalash ----
    if($cdata=="copy_ref_link"){
        $link="https://t.me/{$bot_username}?start=ref_".$user["tg_id"];
        sendMsg($chat_id,"🔗 <b>Sizning havolangiz:</b>\n\n<code>$link</code>\n\n👆 <i>Ustiga bosib nusxalang va do'stlaringizga yuboring!</i>");
        exit;
    }

    // ---- SPEAKING PART START ----
    if($cdata=="speak_part1"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        // Qoida bor bo'lsa ko'rsat, boshlash tugmasi bilan
        $rule=getSpeakingRule(1);
        $kb=["inline_keyboard"=>[[["text"=>"▶️ Boshlash","callback_data"=>"sp1_start"]]]];
        if($rule && !empty(trim($rule["content"]))){
            sendMsg($chat_id,
                "🎯 <b>SPEAKING — PART 1 (Interview)</b>\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━\n"
                ."📜 <b>Qoidalar:</b>\n\n"
                .$rule["content"]."\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━",
                $kb);
        } else {
            sendMsg($chat_id,
                "🎯 <b>SPEAKING — PART 1 (Interview)</b>\n\n"
                ."📋 Jami: <b>3 ta savol</b>\n"
                ."⏱️ Har bir savol: <b>5s tayyorlanish + 30s javob</b>\n"
                ."🎤 Barcha javoblar birga tahlil qilinadi\n"
                ."💡 <b>1 ta imkoniyat = 3 ta savol</b>",
                $kb);
        }
        exit;
    }
    if($cdata=="sp1_start"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        $questions=getRandomSpeakingQuestions(1,3);
        if(empty($questions)){ sendMsg($chat_id,"❌ Part 1 uchun savollar yo'q. Admin qo'shishi kerak."); exit; }
        $questions_json=json_encode($questions);
        $q_clean=preg_replace('/^#\S+\s*/','',trim($questions[0]["question"]));
        setState($user_id,"sp1_prep","|0|".$questions_json."|".base64_encode("[]"));
        // Savol chiqar — tayyorlanish vaqtida keyboard yo'q
        sendMsg($chat_id,
            "🎙️ <b>SPEAKING — PART 1</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."❓ <b>Savol 1 / 3</b>\n\n"
            ."<b>".htmlspecialchars($q_clean)."</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."⏳ <b>10 soniya</b> tayyorlanish boshlanmoqda...",
            noKb());
        $tr=sendTimerPrep($chat_id,$user_id,10,"sp1_prep"); if($tr!=='done') exit;
        // Javob vaqti — faqat to'xtatish
        sendMsg($chat_id,"🎤 <b>Gapiring!</b> Ovozli xabar yuboring.",stopOnlyKb());
        $tr=sendTimerAnswer($chat_id,$user_id,30,"sp1_prep"); if($tr!=='done') exit;
        setState($user_id,"sp1_ans","|0|".$questions_json."|".base64_encode("[]")); exit;
    }

    if($cdata=="speak_part2"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        $rule=getSpeakingRule(2);
        $kb=["inline_keyboard"=>[[["text"=>"▶️ Boshlash","callback_data"=>"sp2_start"]]]];
        if($rule && !empty(trim($rule["content"]))){
            sendMsg($chat_id,
                "📖 <b>SPEAKING — PART 2</b>\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━\n"
                ."📜 <b>Qoidalar:</b>\n\n"
                .$rule["content"]."\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━",
                $kb);
        } else {
            sendMsg($chat_id,
                "📖 <b>SPEAKING — PART 2</b>\n\n"
                ."📋 <b>1 daqiqa</b> tayyorlanish + <b>2 daqiqa</b> javob\n"
                ."📷 Rasm va savol beriladi",
                $kb);
        }
        exit;
    }
    if($cdata=="sp2_start"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        $questions=getRandomSpeakingQuestions(2,1);
        if(empty($questions)){ sendMsg($chat_id,"❌ Part 2 uchun rasm+savol yo'q. Admin qo'shishi kerak."); exit; }
        $q=$questions[0]; $q_clean=preg_replace('/^#\S+\s*/','',trim($q["question"]));
        // Savol va rasm — keyboard yo'q (tayyorlanish boshlanadi)
        $caption="🎙️ <b>SPEAKING — PART 2</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."❓ <b>Savol:</b>\n\n"
            ."<b>".htmlspecialchars($q_clean)."</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."⏳ <b>1 daqiqa</b> tayyorlanish boshlanmoqda...";
        if(!empty($q["photo_file_id"])){
            $r=sendPhoto($chat_id,$q["photo_file_id"],$caption,noKb());
            if(empty($r["ok"])) sendMsg($chat_id,$caption,noKb());
        } else { sendMsg($chat_id,$caption,noKb()); }
        setState($user_id,"sp2_timer",(string)$q["id"]);
        $tr=sendTimerPrep($chat_id,$user_id,60,"sp2_timer"); if($tr!=='done') exit;
        sendMsg($chat_id,"🎤 <b>Gapiring!</b> Ovozli xabar yuboring.",stopOnlyKb());
        $tr=sendTimerAnswer($chat_id,$user_id,120,"sp2_timer"); if($tr!=='done') exit;
        setState($user_id,"sp2_ans",(string)$q["id"]); exit;
    }

    if($cdata=="speak_part3"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        $rule=getSpeakingRule(3);
        $kb=["inline_keyboard"=>[[["text"=>"▶️ Boshlash","callback_data"=>"sp3_start"]]]];
        if($rule && !empty(trim($rule["content"]))){
            sendMsg($chat_id,
                "💬 <b>SPEAKING — PART 3</b>\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━\n"
                ."📜 <b>Qoidalar:</b>\n\n"
                .$rule["content"]."\n\n"
                ."━━━━━━━━━━━━━━━━━━━━━",
                $kb);
        } else {
            sendMsg($chat_id,
                "💬 <b>SPEAKING — PART 3</b>\n\n"
                ."📋 <b>1 daqiqa</b> tayyorlanish + <b>2 daqiqa</b> javob\n"
                ."📷 Rasm va savol beriladi",
                $kb);
        }
        exit;
    }
    if($cdata=="sp3_start"){
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        $questions=getRandomSpeakingQuestions(3,1);
        if(empty($questions)){ sendMsg($chat_id,"❌ Part 3 uchun rasm+savol yo'q. Admin qo'shishi kerak."); exit; }
        $q=$questions[0]; $q_clean=preg_replace('/^#\S+\s*/','',trim($q["question"]));
        $caption="🎙️ <b>SPEAKING — PART 3</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."❓ <b>Savol:</b>\n\n"
            ."<b>".htmlspecialchars($q_clean)."</b>\n\n"
            ."━━━━━━━━━━━━━━━━━━━━━\n"
            ."⏳ <b>1 daqiqa</b> tayyorlanish boshlanmoqda...";
        if(!empty($q["photo_file_id"])){
            $r=sendPhoto($chat_id,$q["photo_file_id"],$caption,noKb());
            if(empty($r["ok"])) sendMsg($chat_id,$caption,noKb());
        } else { sendMsg($chat_id,$caption,noKb()); }
        setState($user_id,"sp3_timer",(string)$q["id"]);
        $tr=sendTimerPrep($chat_id,$user_id,60,"sp3_timer"); if($tr!=='done') exit;
        sendMsg($chat_id,"🎤 <b>Gapiring!</b> Ovozli xabar yuboring.",stopOnlyKb());
        $tr=sendTimerAnswer($chat_id,$user_id,120,"sp3_timer"); if($tr!=='done') exit;
        setState($user_id,"sp3_ans",(string)$q["id"]); exit;
    }

    // ---- WRITING TASK ----
    if(strpos($cdata,"wtask_")===0){
        $task_code=str_replace("wtask_","",$cdata); $info=getTaskInfo($task_code);
        if(!$info){ sendMsg($chat_id,"❌ Noma'lum task."); exit; }
        $prem=isPremium($user);
        if(!$prem && $user["checks"]<=0){ sendMsg($chat_id,"❌ Tekshiruvlar tugadi!",["inline_keyboard"=>[[["text"=>"⭐ Premium","callback_data"=>"show_premium"]]]]); exit; }
        setState($user_id,"wait_writing_q",$task_code);
        $txt="📝 <b>".$info["name"]."</b>\n📋 Format: <b>".$info["format"]."</b>\n🔤 Uslub: <b>".$info["register"]."</b>\n✍️ Hajm: <b>".$info["min_words"]."–".$info["max_words"]." so'z</b>\n\n".$info["desc"]."\n\n".$info["tips_uz"]."\n\n<b>Topshiriq (savol) matnini yuboring:</b>\n<i>Misol:\n".htmlspecialchars($info["example_q"])."</i>";
        sendMsg($chat_id,$txt,backKb()); exit;
    }

    // ---- TO'LOV ----
    if($cdata=="topup_start"){
        $ws=db()->query("SELECT * FROM wallets WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
        if(empty($ws)){ sendMsg($chat_id,"❌ Hozircha to'lov usullari yo'q."); exit; }
        $kb=["inline_keyboard"=>[]];
        foreach($ws as $w) $kb["inline_keyboard"][]=[["text"=>"💳 ".$w["name"],"callback_data"=>"show_wallet_".$w["id"]]];
        $kb["inline_keyboard"][]=[["text"=>"❌ Bekor","callback_data"=>"close_topup"]];
        sendMsg($chat_id,"💳 <b>To'lov usulini tanlang:</b>",$kb); exit;
    }
    if(strpos($cdata,"show_wallet_")===0){
        $wid=(int)str_replace("show_wallet_","",$cdata);
        $q=db()->prepare("SELECT * FROM wallets WHERE id=? AND active=1"); $q->execute([$wid]); $w=$q->fetch(PDO::FETCH_ASSOC);
        if(!$w){ sendMsg($chat_id,"❌ Topilmadi."); exit; }
        $txt="💳 <b>".$w["name"]."</b>\n\n🔢 Raqam: <code>".$w["number"]."</code>\n👤 Egasi: <b>".$w["holder"]."</b>\n";
        if($w["description"]) $txt.="ℹ️ ".$w["description"]."\n";
        $txt.="\n👆 To'lov qilib, <b>To'lov qildim</b> tugmasini bosing.";
        $kb=["inline_keyboard"=>[[["text"=>"✅ To'lov qildim","callback_data"=>"paid_".$wid]],[["text"=>"🔙 Orqaga","callback_data"=>"topup_start"]]]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if(strpos($cdata,"paid_")===0 && !$ia){
        $wid=(int)str_replace("paid_","",$cdata); setState($user_id,"wait_amount",(string)$wid);
        sendMsg($chat_id,"💰 <b>Qancha to'lov qildingiz?</b>\n\n<code>50000</code>",backKb()); exit;
    }
    if($cdata=="close_topup"){ clearState($user_id); sendMsg($chat_id,"❌ Bekor.",mainKb($user_id==$admin_id)); exit; }

    // ---- PREMIUM ----
    if($cdata=="show_premium"){
        $price=(float)getSetting('premium_price'); $prem_ch=(int)getSetting('premium_checks'); $cur_s=getSetting('currency');
        if(isPremium($user)){ sendMsg($chat_id,"⭐ <b>Siz hozir Premium holatdasiz!</b>\n⏳ ".date("d.m.Y",$user["premium_expire"])." gacha"); exit; }
        $has=($user["balance"]>=$price);
        $txt="👑 <b>CEFR MULTILEVEL PREMIUM</b>\n\n💰 Narxi: <b>".fmt($price)." $cur_s / oy</b>\n✍️ ".fmt($prem_ch)." ta tekshiruv\n\n✅ To'liq CEFR tahlil\n✅ Speaking Part 1/2/3 batafsil\n✅ Writing CEFR + yaxshilangan versiya\n✅ C1 namuna javoblar\n\n💵 Balansingiz: <b>".fmt($user["balance"])." $cur_s</b>\n\n".($has?"✅ Sotib olishingiz mumkin!":"❌ Mablag' yetarli emas.");
        $kb=["inline_keyboard"=>[]];
        if($has) $kb["inline_keyboard"][]=[["text"=>"✅ Sotib olish","callback_data"=>"confirm_premium"]];
        $kb["inline_keyboard"][]=[["text"=>"💳 Hisob to'ldirish","callback_data"=>"topup_start"]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($cdata=="confirm_premium"){
        $price=(float)getSetting('premium_price'); $prem_ch=(int)getSetting('premium_checks'); $cur_s=getSetting('currency');
        $user=getUser($user_id);
        if($user["balance"]<$price){ sendMsg($chat_id,"❌ Mablag' yetarli emas."); exit; }
        if(isPremium($user)){ sendMsg($chat_id,"✅ Siz allaqachon Premium!"); exit; }
        sendMsg($chat_id,"⭐ <b>Premium sotib olish</b>\n\n💰 <b>".fmt($price)." $cur_s</b>\n📅 1 oy | ✍️ ".fmt($prem_ch)." ta\n\n<b>Tasdiqlaysizmi?</b>",["inline_keyboard"=>[[["text"=>"✅ Ha","callback_data"=>"buy_prem_yes"],["text"=>"❌ Yo'q","callback_data"=>"buy_prem_no"]]]]);exit;
    }
    if($cdata=="buy_prem_yes"){
        $price=(float)getSetting('premium_price'); $prem_ch=(int)getSetting('premium_checks'); $cur_s=getSetting('currency');
        $user=getUser($user_id);
        if($user["balance"]<$price){ sendMsg($chat_id,"❌ Mablag' yetarli emas."); exit; }
        if(isPremium($user)){ sendMsg($chat_id,"✅ Allaqachon Premium!"); exit; }
        $exp=time()+(30*24*3600);
        db()->prepare("UPDATE users SET premium=1,premium_expire=?,balance=balance-?,checks=? WHERE tg_id=?")->execute([$exp,$price,$prem_ch,$user_id]);
        sendMsg($chat_id,"🎉 <b>Premium faollashtirildi!</b>\n\n⏳ ".date("d.m.Y",$exp)." gacha\n✍️ ".fmt($prem_ch)." ta tekshiruv\n💵 -".fmt($price)." $cur_s",mainKb($user_id==$admin_id)); exit;
    }
    if($cdata=="buy_prem_no"){ sendMsg($chat_id,"❌ Bekor.",mainKb($user_id==$admin_id)); exit; }

    // ---- TO'LOV TASDIQLASH ADMIN ----
    if($ia && strpos($cdata,"approve_pay_")===0){
        $pid=(int)str_replace("approve_pay_","",$cdata);
        $q=db()->prepare("SELECT * FROM payments WHERE id=?"); $q->execute([$pid]); $pay=$q->fetch(PDO::FETCH_ASSOC);
        if(!$pay||$pay["status"]!="pending"){ sendMsg($chat_id,"❌ Allaqachon ko'rib chiqilgan."); exit; }
        $cur_s=getSetting('currency');
        db()->prepare("UPDATE payments SET status='approved' WHERE id=?")->execute([$pid]);
        db()->prepare("UPDATE users SET balance=balance+? WHERE tg_id=?")->execute([$pay["amount"],$pay["tg_id"]]);
        $nb=getUser($pay["tg_id"])["balance"];
        editMsg($chat_id,$mid,"✅ <b>Tasdiqlandi #$pid</b>\n🆔 ".$pay["tg_id"]."\n💰 ".fmt($pay["amount"])." $cur_s\n💵 Balans: ".fmt($nb)." $cur_s");
        sendMsg($pay["tg_id"],"✅ <b>To'lovingiz tasdiqlandi!</b>\n💰 +".fmt($pay["amount"])." $cur_s\n💵 Balans: ".fmt($nb)." $cur_s"); exit;
    }
    if($ia && strpos($cdata,"reject_pay_")===0){
        $pid=(int)str_replace("reject_pay_","",$cdata);
        $q=db()->prepare("SELECT * FROM payments WHERE id=?"); $q->execute([$pid]); $pay=$q->fetch(PDO::FETCH_ASSOC);
        if(!$pay||$pay["status"]!="pending"){ sendMsg($chat_id,"❌ Allaqachon ko'rib chiqilgan."); exit; }
        db()->prepare("UPDATE payments SET status='rejected' WHERE id=?")->execute([$pid]);
        $cur_s=getSetting('currency');
        editMsg($chat_id,$mid,"❌ <b>Rad etildi #$pid</b>\n🆔 ".$pay["tg_id"]."\n💰 ".fmt($pay["amount"])." $cur_s");
        sendMsg($pay["tg_id"],"❌ <b>To'lovingiz rad etildi.</b> Admin bilan bog'laning."); exit;
    }

    // ---- ADMIN PANEL CALLBACKS ----
    if($ia && $cdata=="adm_back"){ clearState($admin_id); sendMsg($chat_id,"🛠 <b>Admin Panel</b>",panelKb()); exit; }

    // ---- SPEAK QOIDA CALLBACKS ----
    if($ia && strpos($cdata,"adm_rule_menu_")===0){
        $part=(int)str_replace("adm_rule_menu_","",$cdata);
        $rule=getSpeakingRule($part);
        $pnames=["","🎯 Part 1 — Interview","📖 Part 2 — Long Turn","💬 Part 3 — Discussion"];
        $kb=["inline_keyboard"=>[]];
        if($rule){
            $kb["inline_keyboard"][]=[["text"=>"✏️ Tahrirlash","callback_data"=>"adm_rule_edit_$part"],["text"=>"🗑 O'chirish","callback_data"=>"adm_rule_del_$part"]];
        } else {
            $kb["inline_keyboard"][]=[["text"=>"➕ Qoida kiritish","callback_data"=>"adm_rule_edit_$part"]];
        }
        $kb["inline_keyboard"][]=[["text"=>"🔙 Orqaga","callback_data"=>"adm_rule_back"]];
        $txt="📜 <b>".$pnames[$part]." Qoidasi</b>\n\n";
        if($rule){
            $txt.="<b>Joriy qoida:</b>\n\n".htmlspecialchars($rule["content"]);
        } else {
            $txt.="<i>Hali qoida kiritilmagan.</i>";
        }
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($ia && strpos($cdata,"adm_rule_edit_")===0){
        $part=(int)str_replace("adm_rule_edit_","",$cdata);
        $pnames=["","Part 1","Part 2","Part 3"];
        setState($admin_id,"adm_speak_rule_$part");
        sendMsg($chat_id,"✏️ <b>".$pnames[$part]." uchun yangi qoidani yuboring:</b>\n\n<i>Xohlagan formatda yozing — bold, italic, emoji, har narsani qo'llashingiz mumkin.\nYuborgan xabaringiz aynan shu ko'rinishda saqlandi.</i>",backPanel()); exit;
    }
    if($ia && strpos($cdata,"adm_rule_del_")===0){
        $part=(int)str_replace("adm_rule_del_","",$cdata);
        db()->prepare("DELETE FROM speaking_rules WHERE part=?")->execute([$part]);
        clearState($admin_id);
        $r1=getSpeakingRule(1); $r2=getSpeakingRule(2); $r3=getSpeakingRule(3);
        $kb=["inline_keyboard"=>[
            [["text"=>"🎯 Part 1 qoida".($r1?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_1"]],
            [["text"=>"📖 Part 2 qoida".($r2?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_2"]],
            [["text"=>"💬 Part 3 qoida".($r3?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_3"]],
            [["text"=>"🔙 Panel","callback_data"=>"adm_back"]],
        ]];
        sendMsg($chat_id,"🗑 <b>Part $part qoidasi o'chirildi.</b>\n\n📜 Speak Qoidalar:",$kb); exit;
    }
    if($ia && $cdata=="adm_rule_back"){
        $r1=getSpeakingRule(1); $r2=getSpeakingRule(2); $r3=getSpeakingRule(3);
        $kb=["inline_keyboard"=>[
            [["text"=>"🎯 Part 1 qoida".($r1?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_1"]],
            [["text"=>"📖 Part 2 qoida".($r2?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_2"]],
            [["text"=>"💬 Part 3 qoida".($r3?"  ✅":"  ➕"),"callback_data"=>"adm_rule_menu_3"]],
            [["text"=>"🔙 Panel","callback_data"=>"adm_back"]],
        ]];
        sendMsg($chat_id,"📜 <b>Speaking Qoidalar</b>\n\nPart 1: ".($r1?"✅ Bor":"❌ Yo'q")."\nPart 2: ".($r2?"✅ Bor":"❌ Yo'q")."\nPart 3: ".($r3?"✅ Bor":"❌ Yo'q"),$kb); exit;
    }
    if($ia && strpos($cdata,"adm_delch_")===0){
        $ch=urldecode(str_replace("adm_delch_","",$cdata));
        db()->prepare("DELETE FROM channels WHERE username=?")->execute([$ch]);
        clearState($admin_id); sendMsg($chat_id,"✅ $ch o'chirildi.\n\n🛠 Panel:",panelKb()); exit;
    }
    if($ia && strpos($cdata,"adm_wtoggle_")===0){
        $wid=(int)str_replace("adm_wtoggle_","",$cdata);
        $q=db()->prepare("SELECT active FROM wallets WHERE id=?"); $q->execute([$wid]); $ca=(int)$q->fetchColumn();
        db()->prepare("UPDATE wallets SET active=? WHERE id=?")->execute([$ca?0:1,$wid]);
        sendMsg($chat_id,($ca?"❌ O'chirildi.":"✅ Yoqildi.")."\n\n🛠 Panel:",panelKb()); exit;
    }
    if($ia && strpos($cdata,"adm_wdel_")===0){
        $wid=(int)str_replace("adm_wdel_","",$cdata);
        db()->prepare("DELETE FROM wallets WHERE id=?")->execute([$wid]);
        sendMsg($chat_id,"🗑 Hamyon o'chirildi.\n\n🛠 Panel:",panelKb()); exit;
    }
    if($ia && $cdata=="adm_set_price"){ setState($admin_id,"adm_setprice"); sendMsg($chat_id,"💵 Yangi premium narxi:\n<code>50000</code>",backPanel()); exit; }
    if($ia && $cdata=="adm_set_premiumchecks"){ setState($admin_id,"adm_setpremiumchecks"); sendMsg($chat_id,"✍️ Premium tekshiruv soni:\n<code>400</code>",backPanel()); exit; }
    if($ia && $cdata=="adm_set_freechecks"){ setState($admin_id,"adm_setfreechecks"); sendMsg($chat_id,"🆓 Bepul tekshiruv soni:\n<code>3</code>",backPanel()); exit; }
    if($ia && $cdata=="adm_set_currency"){
        $kb=["inline_keyboard"=>[[["text"=>"🇺🇿 UZS","callback_data"=>"adm_cur_UZS"],["text"=>"🇺🇸 USD","callback_data"=>"adm_cur_USD"]],[["text"=>"🇷🇺 RUB","callback_data"=>"adm_cur_RUB"],["text"=>"🇪🇺 EUR","callback_data"=>"adm_cur_EUR"]],[["text"=>"🔙 Panel","callback_data"=>"adm_back"]]]];
        sendMsg($chat_id,"💱 Valyutani tanlang:",$kb); exit;
    }
    if($ia && strpos($cdata,"adm_cur_")===0){ $v=str_replace("adm_cur_","",$cdata); setSetting('currency',$v); clearState($admin_id); sendMsg($chat_id,"✅ Valyuta: <b>$v</b>\n\n🛠 Panel:",panelKb()); exit; }
    if($ia && $cdata=="adm_togglebot"){ $new=getSetting('bot_active')=="1"?"0":"1"; setSetting('bot_active',$new); clearState($admin_id); sendMsg($chat_id,"🤖 Bot: ".($new=="1"?"✅ Yoqildi":"❌ O'chirildi")."\n\n🛠 Panel:",panelKb()); exit; }
    if($ia && $cdata=="adm_setoffmsg"){ setState($admin_id,"adm_offmsg"); sendMsg($chat_id,"✏️ Yangi off xabarni yuboring:",backPanel()); exit; }
    if($ia && strpos($cdata,"adm_addbal_")===0){ $uid=(int)str_replace("adm_addbal_","",$cdata); setState($admin_id,"adm_addbal",(string)$uid); sendMsg($chat_id,"💰 Qancha qo'shish?\n<code>10000</code>",backPanel()); exit; }
    if($ia && strpos($cdata,"adm_subbal_")===0){ $uid=(int)str_replace("adm_subbal_","",$cdata); setState($admin_id,"adm_subbal",(string)$uid); sendMsg($chat_id,"💸 Qancha ayirish?\n<code>10000</code>",backPanel()); exit; }
    if($ia && strpos($cdata,"adm_toggleprem_")===0){
        $uid=(int)str_replace("adm_toggleprem_","",$cdata); $tg=getUser($uid);
        if(!$tg){ sendMsg($chat_id,"❌ Topilmadi."); exit; }
        $pch=(int)getSetting('premium_checks');
        if(isPremium($tg)){
            db()->prepare("UPDATE users SET premium=0,premium_expire=0 WHERE tg_id=?")->execute([$uid]);
            sendMsg($chat_id,"✅ Free ga o'tkazildi."); sendMsg($uid,"⚠️ Premium obunangiz admin tomonidan bekor qilindi.");
        } else {
            $exp=time()+(30*24*3600); db()->prepare("UPDATE users SET premium=1,premium_expire=?,checks=? WHERE tg_id=?")->execute([$exp,$pch,$uid]);
            sendMsg($chat_id,"✅ Premium ga o'tkazildi."); sendMsg($uid,"🎉 <b>Admin sizni Premium qildi!</b>\n⏳ ".date("d.m.Y",$exp)." gacha\n✍️ ".fmt($pch)." ta tekshiruv");
        } exit;
    }

    // ---- SPEAKING SAVOLLAR ADMIN ----
    if($ia && strpos($cdata,"adm_sq_list_")===0){
        $part=(int)str_replace("adm_sq_list_","",$cdata);
        $qs=db()->prepare("SELECT * FROM speaking_questions WHERE part=? ORDER BY id DESC LIMIT 20"); $qs->execute([$part]);
        $list=$qs->fetchAll(PDO::FETCH_ASSOC);
        $txt="📋 <b>Part $part Savollar</b>\n\n"; $kb=["inline_keyboard"=>[]];
        if(empty($list)) $txt.="Savol yo'q.\n";
        foreach($list as $q){
            $short=mb_substr(preg_replace('/^#\S+\s*/','',trim($q["question"])),0,35)."…";
            $has_photo=$q["photo_file_id"]?"📷":"";
            $status=$q["active"]?"✅":"❌";
            $kb["inline_keyboard"][]=[["text"=>"$status$has_photo ".htmlspecialchars($short),"callback_data"=>"adm_sq_toggle_".$q["id"]],["text"=>"🗑","callback_data"=>"adm_sq_del_".$q["id"]]];
        }
        $add_cb=($part==1)?"adm_sq_addpart_1":"adm_sq_addpart_$part";
        $kb["inline_keyboard"][]=[["text"=>"➕ Savol qo'sh","callback_data"=>$add_cb]];
        $kb["inline_keyboard"][]=[["text"=>"🔙 Orqaga","callback_data"=>"adm_sq_main"]];
        sendMsg($chat_id,$txt,$kb); exit;
    }
    if($ia && $cdata=="adm_sq_main"){
        $p1=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=1 AND active=1")->fetchColumn();
        $p2=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=2 AND active=1")->fetchColumn();
        $p3=db()->query("SELECT COUNT(*) FROM speaking_questions WHERE part=3 AND active=1")->fetchColumn();
        $kb=["inline_keyboard"=>[
            [["text"=>"📋 Part 1 ($p1 ta)","callback_data"=>"adm_sq_list_1"]],
            [["text"=>"📋 Part 2 ($p2 ta)","callback_data"=>"adm_sq_list_2"]],
            [["text"=>"📋 Part 3 ($p3 ta)","callback_data"=>"adm_sq_list_3"]],
            [["text"=>"➕ Part 1 savol","callback_data"=>"adm_sq_addpart_1"]],
            [["text"=>"➕ Part 2 savol (rasm+savol)","callback_data"=>"adm_sq_addpart_2"]],
            [["text"=>"➕ Part 3 savol (rasm+savol)","callback_data"=>"adm_sq_addpart_3"]],
            [["text"=>"🔙 Panel","callback_data"=>"adm_back"]],
        ]];
        sendMsg($chat_id,"🎤 <b>Speaking Savollar</b>\n\nPart 1: $p1 ta\nPart 2: $p2 ta\nPart 3: $p3 ta",$kb); exit;
    }
    if($ia && strpos($cdata,"adm_sq_addpart_")===0){
        $part=(int)str_replace("adm_sq_addpart_","",$cdata);
        if($part==1){
            setState($admin_id,"adm_add_speak_q_1");
            sendMsg($chat_id,"➕ <b>Part 1 savol qo'shish</b>\n\nHar bir savol <b>#</b> bilan boshlansin:\n\n<code>#savol1\nTell me about yourself.\n\n#savol2\nWhat do you like?\n\n#savol3\nDescribe your home.</code>\n\nYuboring 👇",backPanel());
        } else {
            setState($admin_id,"adm_add_speak_q_{$part}_photo");
            sendMsg($chat_id,"➕ <b>Part $part savol qo'shish</b>\n\n<b>1-qadam:</b> Rasm yuboring 📷\n(Rasm yuborilgandan so'ng savol so'raladi)",backPanel());
        }
        exit;
    }
    if($ia && strpos($cdata,"adm_sq_toggle_")===0){
        $qid=(int)str_replace("adm_sq_toggle_","",$cdata);
        $q_r=db()->prepare("SELECT * FROM speaking_questions WHERE id=?"); $q_r->execute([$qid]); $q_obj=$q_r->fetch(PDO::FETCH_ASSOC);
        if(!$q_obj){ sendMsg($chat_id,"❌ Topilmadi."); exit; }
        $new_a=$q_obj["active"]?0:1;
        db()->prepare("UPDATE speaking_questions SET active=? WHERE id=?")->execute([$new_a,$qid]);
        sendMsg($chat_id,($new_a?"✅ Savol faollashtirildi.":"❌ Savol o'chirildi.")." (ID:$qid)"); exit;
    }
    if($ia && strpos($cdata,"adm_sq_del_")===0){
        $qid=(int)str_replace("adm_sq_del_","",$cdata);
        db()->prepare("DELETE FROM speaking_questions WHERE id=?")->execute([$qid]);
        sendMsg($chat_id,"🗑 Savol o'chirildi (ID:$qid)"); exit;
    }

    // ---- BROADCAST CONFIRM ----
    if($ia && $cdata=="adm_bc_confirm"){
        $sd=$user["state_data"]; $pts_bc=explode("|",$sd,2);
        $from_chat_bc=(int)$pts_bc[0]; $msg_id_bc=(int)$pts_bc[1];
        clearState($admin_id);
        $all_users=db()->query("SELECT tg_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $total=count($all_users); $sent=0; $fail=0;
        $prog=sendMsg($chat_id,"📣 <b>Yuborilmoqda...</b>\n\n⏳ 0 / $total");
        $pmid=$prog["result"]["message_id"]??null;
        foreach($all_users as $i=>$uid){
            $r=copyMessage($uid,$from_chat_bc,$msg_id_bc);
            if(!empty($r["ok"])) $sent++; else $fail++;
            if($pmid && ($i+1)%20==0) editMsg($chat_id,$pmid,"📣 <b>Yuborilmoqda...</b>\n\n⏳ ".($i+1)." / $total\n✅ $sent\n❌ $fail");
            usleep(50000);
        }
        if($pmid) editMsg($chat_id,$pmid,"✅ <b>Reklama yuborildi!</b>\n\n👥 Jami: $total\n✅ Muvaffaqiyatli: $sent\n❌ Yuborilmadi: $fail");
        exit;
    }
    if($ia && $cdata=="adm_bc_cancel"){ clearState($admin_id); sendMsg($chat_id,"❌ Bekor.\n\n🛠 Panel:",panelKb()); exit; }

    exit;
}

/* ============================================================
   DEFAULT
============================================================ */
if(!$message) exit;

$speaking_states=["sp1_prep","sp1_ans","sp1_ans_proc","sp2_prep","sp2_timer","sp2_ans","sp2_ans_proc","sp3_prep","sp3_timer","sp3_ans","sp3_ans_proc"];
$writing_states=["wait_amount","wait_check","check_writing","wait_writing_q"];
$admin_states=["adm_add_channel","adm_add_wallet","adm_set_reward","adm_setprice","adm_setfreechecks","adm_setpremiumchecks","adm_offmsg","adm_user_search","adm_addbal","adm_subbal","adm_add_speak_q_1","adm_add_speak_q_2_photo","adm_add_speak_q_2_text","adm_add_speak_q_3_photo","adm_add_speak_q_3_text","adm_broadcast_wait","adm_broadcast_confirm","adm_speak_rule_1","adm_speak_rule_2","adm_speak_rule_3"];
$all_states=array_merge($writing_states,$speaking_states,$admin_states);

if($text && $text[0]!="/" && !in_array($state,$all_states)){
    sendMsg($chat_id,"📱 Menyudan bo'lim tanlang:",mainKb($user_id==$admin_id));
}
