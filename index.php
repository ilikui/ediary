<?php
declare(strict_types=1);
session_start();
const APP_NAME = 'eDiary';
$dbDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ediary-data';
$dbFile = $dbDir . DIRECTORY_SEPARATOR . 'ediary.sqlite';
if (!is_dir($dbDir) && !mkdir($dbDir, 0750, true) && !is_dir($dbDir)) { http_response_code(500); exit('无法创建数据目录，请检查权限。'); }
try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL DEFAULT '', content TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', is_pinned INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(notes)')->fetchAll() as $col) $columns[$col['name']] = true;
    $migrations = [
        'note_type' => "ALTER TABLE notes ADD COLUMN note_type TEXT NOT NULL DEFAULT 'note'",
        'is_public' => "ALTER TABLE notes ADD COLUMN is_public INTEGER NOT NULL DEFAULT 0",
        'slug' => "ALTER TABLE notes ADD COLUMN slug TEXT NOT NULL DEFAULT ''",
        'journal_date' => "ALTER TABLE notes ADD COLUMN journal_date TEXT NOT NULL DEFAULT ''",
        'published_at' => "ALTER TABLE notes ADD COLUMN published_at TEXT NOT NULL DEFAULT ''"
    ];
    foreach ($migrations as $name=>$sql) if (!isset($columns[$name])) $pdo->exec($sql);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_user_updated ON notes(user_id, updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_public_slug ON notes(is_public, slug)');
} catch (Throwable $e) { http_response_code(500); exit('数据库初始化失败。请确认 PHP 已启用 PDO_SQLITE，且数据目录可写。'); }

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('表单验证已过期，请返回后重试。'); } }
function redirect_to(string $url = 'index.php') { header('Location: '.$url); exit; }
function logged_in(): bool { return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']); }
function slugify(string $text): string {
    $text = trim(function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    $text = preg_replace('/[^\pL\pN]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return substr($text !== '' ? $text : 'note', 0, 70);
}
function markdown_html(string $source): string {
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $parts = preg_split('/(\x60\x60\x60[^\n]*\n.*?\x60\x60\x60)/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $part) {
        if (preg_match('/^\x60\x60\x60([^\n]*)\n(.*?)\x60\x60\x60$/s', $part, $m)) {
            $out .= '<pre><code class="language-'.e(trim($m[1])).'">'.e($m[2]).'</code></pre>';
            continue;
        }
        $safe = e($part);
        $safe = preg_replace_callback('/\x60([^\x60\n]+)\x60/', fn($m)=>'<code>'.$m[1].'</code>', $safe) ?? $safe;
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
        $safe = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $safe) ?? $safe;
        $safe = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $safe) ?? $safe;
        $safe = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/^https?:\/\//i', $url)) return $m[1];
            return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.$m[1].'</a>';
        }, $safe) ?? $safe;
        $safe = preg_replace_callback('/\\[\\[([^\\]]+)\\]\\]/u', function($m) {
            $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '<a href="index.php?wiki='.e(rawurlencode($title)).'">'.$m[1].'</a>';
        }, $safe) ?? $safe;
        $lines = explode("\n", $safe); $rendered = []; $list = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
                if (!$list) { $rendered[] = '<ul>'; $list = true; }
                $rendered[] = '<li>'.$m[1].'</li>'; continue;
            }
            if ($list) { $rendered[] = '</ul>'; $list = false; }
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]); $rendered[] = '<h'.$level.'>'.$m[2].'</h'.$level.'>';
            } elseif (preg_match('/^&gt;\s?(.*)$/', $line, $m)) $rendered[] = '<blockquote>'.$m[1].'</blockquote>';
            elseif (trim($line) === '') $rendered[] = '<div class="md-space"></div>';
            else $rendered[] = '<p>'.$line.'</p>';
        }
        if ($list) $rendered[] = '</ul>';
        $out .= implode("\n", $rendered);
    }
    return $out;
}
function parse_tags(string $tags): array { return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，;；]+/u', $tags) ?: []), fn($v)=>$v!==''))); }

$error = ''; $notice = '';
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$publicSlug = trim((string)($_GET['public'] ?? ''));
if ($publicSlug !== '') {
    $stmt = $pdo->prepare('SELECT n.*, u.username FROM notes n JOIN users u ON u.id=n.user_id WHERE n.slug=? AND n.is_public=1 LIMIT 1');
    $stmt->execute([$publicSlug]); $publicNote = $stmt->fetch();
    if (!$publicNote) { http_response_code(404); $publicNote = null; }
    ?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $publicNote ? e($publicNote['title']).' · '.APP_NAME : '文章不存在 · '.APP_NAME ?></title><style>
    *{box-sizing:border-box}body{margin:0;background:#f5f6fa;color:#252b3b;font:16px/1.85 system-ui,"Microsoft YaHei",sans-serif}.top{padding:20px 5vw;background:#fff;border-bottom:1px solid #e7e9f0}.top a{font-weight:800;color:#5c62d6;text-decoration:none}.article{max-width:820px;margin:36px auto;padding:clamp(22px,5vw,56px);background:#fff;border:1px solid #e7e9f0;border-radius:20px}.article h1{line-height:1.3;font-size:clamp(28px,5vw,42px)}.meta{color:#7b8397;font-size:13px}.md h1,.md h2,.md h3,.md h4{line-height:1.35;margin-top:1.5em}.md p{overflow-wrap:anywhere}.md pre{overflow:auto;background:#1e2433;color:#f6f7fb;padding:16px;border-radius:10px}.md code{background:#f0f1f7;border-radius:4px;padding:2px 5px}.md pre code{background:none;color:inherit}.md blockquote{margin:12px 0;padding:4px 16px;border-left:3px solid #8589e8;color:#697186}.md a{color:#5158cb}.md li{margin:4px 0}.md-space{height:8px}.tags{display:flex;gap:8px;flex-wrap:wrap}.tag{font-size:12px;background:#f0f1f7;padding:3px 9px;border-radius:7px;color:#697186}.back{color:#5c62d6;text-decoration:none}.empty{padding:70px 25px;text-align:center}</style></head><body><header class="top"><a href="index.php">eDiary</a> <span style="color:#7b8397">/ 公开知识库</span></header>
    <?php if ($publicNote): ?><article class="article"><div class="meta">公开笔记 · <?= e($publicNote['updated_at']) ?></div><h1><?= e($publicNote['title']) ?></h1><div class="tags"><?php foreach(parse_tags((string)$publicNote['tags']) as $tag): ?><span class="tag"><?= e($tag) ?></span><?php endforeach; ?></div><div class="md"><?= markdown_html((string)$publicNote['content']) ?></div><p><a class="back" href="index.php">← eDiary</a></p></article><?php else: ?><main class="article empty"><h1>文章不存在或尚未公开</h1><p>这条内容可能已取消发布，或链接有误。</p><a class="back" href="index.php">返回 eDiary</a></main><?php endif; ?></body></html><?php exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); $action = (string)($_POST['action'] ?? '');
    if ($action === 'setup' && $userCount === 0) {
        $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$username)) $error='用户名需为 3–40 位，只能包含字母、数字、点、下划线和连字符。';
        elseif (strlen($password)<12) $error='密码至少需要 12 个字符。';
        else { $stmt=$pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)'); $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT)]); session_regenerate_id(true); $_SESSION['user_id']=(int)$pdo->lastInsertId(); $_SESSION['username']=$username; redirect_to(); }
    } elseif ($action === 'login') {
        $stmt=$pdo->prepare('SELECT id,username,password_hash FROM users WHERE username=?'); $stmt->execute([trim((string)($_POST['username']??''))]); $user=$stmt->fetch();
        if ($user && password_verify((string)($_POST['password']??''),$user['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['username']=$user['username']; redirect_to(); }
        $error='用户名或密码不正确。';
    } elseif ($action === 'logout' && logged_in()) {
        $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); } session_destroy(); redirect_to();
    } elseif (logged_in()) {
        $uid=(int)$_SESSION['user_id'];
        if ($action === 'save_note') {
            $id=(int)($_POST['id']??0); $title=trim((string)($_POST['title']??'')); $body=(string)($_POST['content']??''); $tags=trim((string)($_POST['tags']??'')); $pinned=isset($_POST['is_pinned'])?1:0;
            $type=in_array((string)($_POST['note_type']??'note'),['note','card','journal'],true)?(string)$_POST['note_type']:'note';
            $isPublic=isset($_POST['is_public'])?1:0; $journalDate=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_POST['journal_date']??''))?(string)$_POST['journal_date']:'';
            if ($title==='') $error='请填写笔记标题。';
            elseif (function_exists('mb_strlen')?mb_strlen($title,'UTF-8')>200:strlen($title)>600) $error='标题不能超过 200 个字符。';
            elseif (strlen($body)>2000000) $error='单篇笔记正文不能超过 2 MB。';
            else {
                if ($id>0) {
                    $stmt=$pdo->prepare('SELECT id,slug,is_public FROM notes WHERE id=? AND user_id=?'); $stmt->execute([$id,$uid]); $old=$stmt->fetch();
                    if (!$old) $error='找不到这条笔记。';
                    else {
                        $slug=(string)$old['slug']; if ($slug==='') $slug=slugify($title).'-'.$id;
                        $publishedAt=($isPublic && empty($old['is_public']))?date('Y-m-d H:i:s'):($isPublic?date('Y-m-d H:i:s'):'');
                        $stmt=$pdo->prepare('UPDATE notes SET title=?,content=?,tags=?,is_pinned=?,note_type=?,is_public=?,slug=?,journal_date=?,published_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
                        $stmt->execute([$title,$body,$tags,$pinned,$type,$isPublic,$slug,$journalDate,$publishedAt,$id,$uid]);
                    }
                } else {
                    $stmt=$pdo->prepare("INSERT INTO notes(user_id,title,content,tags,is_pinned,note_type,is_public,slug,journal_date,published_at) VALUES(?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$uid,$title,$body,$tags,$pinned,$type,$isPublic,'',$journalDate,$isPublic?date('Y-m-d H:i:s'):'']);
                    $id=(int)$pdo->lastInsertId(); $slug=slugify($title).'-'.$id;
                    $stmt=$pdo->prepare('UPDATE notes SET slug=? WHERE id=? AND user_id=?'); $stmt->execute([$slug,$id,$uid]);
                }
                if ($error==='') redirect_to('index.php?edit='.$id.'&saved=1');
            }
        } elseif ($action === 'delete_note') { $stmt=$pdo->prepare('DELETE FROM notes WHERE id=? AND user_id=?'); $stmt->execute([(int)($_POST['id']??0),$uid]); redirect_to();
        } elseif ($action === 'toggle_pin') { $stmt=$pdo->prepare('UPDATE notes SET is_pinned=1-is_pinned,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?'); $stmt->execute([(int)($_POST['id']??0),$uid]); redirect_to();
        } elseif ($action === 'export_json') {
            $stmt=$pdo->prepare('SELECT title,content,tags,is_pinned,note_type,is_public,slug,journal_date,created_at,updated_at FROM notes WHERE user_id=? ORDER BY id'); $stmt->execute([$uid]);
            $payload=['format'=>'ediary-json','version'=>1,'exported_at'=>date(DATE_ATOM),'notes'=>$stmt->fetchAll()];
            header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="ediary-export-'.date('Ymd-His').'.json"'); echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE); exit;
        } elseif ($action === 'import_json') {
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error']!==UPLOAD_ERR_OK) $error='请选择有效的 JSON 文件。';
            elseif ((int)$_FILES['import_file']['size']>5000000) $error='导入文件不能超过 5 MB。';
            else {
                $raw=file_get_contents($_FILES['import_file']['tmp_name']); $payload=json_decode((string)$raw,true);
                if (!is_array($payload) || !isset($payload['notes']) || !is_array($payload['notes'])) $error='JSON 格式不正确，请使用 eDiary 导出的 JSON 文件。';
                else {
                    $pdo->beginTransaction();
                    try {
                        $insert=$pdo->prepare('INSERT INTO notes(user_id,title,content,tags,is_pinned,note_type,is_public,slug,journal_date,published_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
                        $count=0;
                        foreach (array_slice($payload['notes'],0,1000) as $item) {
                            if (!is_array($item) || trim((string)($item['title']??''))==='') continue;
                            $title=trim((string)$item['title']); $body=(string)($item['content']??'');
                            if (strlen($body)>2000000) continue;
                            $importType=(string)($item['note_type']??'note');
                            $type=in_array($importType,['note','card','journal'],true)?$importType:'note';
                            $insert->execute([$uid,$title,$body,trim((string)($item['tags']??'')),!empty($item['is_pinned'])?1:0,$type,0,'',preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($item['journal_date']??''))?(string)$item['journal_date']:'','']);
                            $newId=(int)$pdo->lastInsertId(); $pdo->prepare('UPDATE notes SET slug=? WHERE id=?')->execute([slugify($title).'-'.$newId,$newId]); $count++;
                        }
                        $pdo->commit(); $notice='导入完成，共导入 '.$count.' 条笔记（全部默认为私密）。';
                    } catch(Throwable $ex) { $pdo->rollBack(); $error='导入失败，数据库已回滚，请检查 JSON 文件。'; }
                }
            }
        } elseif ($action === 'backup_db') {
            if (!class_exists('SQLite3')) $error='当前 PHP 未启用 SQLite3 扩展，无法在线备份；请复制数据库文件前先停止 Apache。';
            else {
                $backupDir=$dbDir.DIRECTORY_SEPARATOR.'backups';
                if (!is_dir($backupDir) && !mkdir($backupDir,0750,true) && !is_dir($backupDir)) $error='无法创建备份目录，请检查权限。';
                else {
                    $backupFile=$backupDir.DIRECTORY_SEPARATOR.'ediary-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
                    try {
                        $source=new SQLite3($dbFile,SQLITE3_OPEN_READONLY); $dest=new SQLite3($backupFile,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
                        $ok=$source->backup($dest); $source->close(); $dest->close();
                        if (!$ok) { @unlink($backupFile); $error='SQLite 备份失败。'; } else $notice='数据库备份已创建：'.$backupFile;
                    } catch(Throwable $ex) { if (isset($source)) $source->close(); if (isset($dest)) $dest->close(); @unlink($backupFile); $error='备份失败，请检查 SQLite3 扩展和目录权限。'; }
                }
            }
        }
    }
}
$userCount=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); $setupRequired=$userCount===0; $editNote=null; $notes=[]; $query=trim((string)($_GET['q']??'')); $tagFilter=trim((string)($_GET['tag']??'')); $dateFilter=trim((string)($_GET['date']??'')); $typeFilter=trim((string)($_GET['type']??'')); $wiki=trim((string)($_GET['wiki']??''));
if (logged_in()) {
    $uid=(int)$_SESSION['user_id']; $editId=(int)($_GET['edit']??0);
    if ($editId>0) { $stmt=$pdo->prepare('SELECT * FROM notes WHERE id=? AND user_id=?'); $stmt->execute([$editId,$uid]); $editNote=$stmt->fetch()?:null; }
    $stmt=$pdo->prepare('SELECT * FROM notes WHERE user_id=? ORDER BY is_pinned DESC,updated_at DESC'); $stmt->execute([$uid]); $allNotes=$stmt->fetchAll();
    $notes=array_values(array_filter($allNotes,function($n) use($query,$tagFilter,$dateFilter,$typeFilter,$wiki) {
        if ($query!=='' && stripos($n['title'].' '.$n['content'].' '.$n['tags'],$query)===false) return false;
        if ($tagFilter!=='' && !in_array($tagFilter,parse_tags((string)$n['tags']),true)) return false;
        if ($dateFilter!=='' && substr((string)($n['journal_date']?:$n['created_at']),0,10)!==$dateFilter) return false;
        if ($typeFilter!=='' && $n['note_type']!==$typeFilter) return false;
        if ($wiki!=='' && (function_exists('mb_strtolower') ? mb_strtolower((string)$n['title'],'UTF-8') : strtolower((string)$n['title']))!==(function_exists('mb_strtolower') ? mb_strtolower($wiki,'UTF-8') : strtolower($wiki))) return false;
        return true;
    }));
    if ($wiki!=='' && !$editNote && count($notes)===1) $editNote=$notes[0];
    $tagsAvailable=[]; foreach($allNotes as $n) foreach(parse_tags((string)$n['tags']) as $tag) $tagsAvailable[$tag]=true; $tagsAvailable=array_keys($tagsAvailable); natcasesort($tagsAvailable);
    $backlinks=[];
    if ($editNote) foreach($allNotes as $candidate) if ((int)$candidate['id']!==(int)$editNote['id'] && preg_match('/\[\['.preg_quote((string)$editNote['title'],'/').'\]\]/u',(string)$candidate['content'])) $backlinks[]=$candidate;
    $calendarCounts=[]; foreach($allNotes as $n) { $d=substr((string)($n['journal_date']?:$n['created_at']),0,10); $calendarCounts[$d]=($calendarCounts[$d]??0)+1; }
}
if (isset($_GET['saved'])) $notice='笔记已保存。';
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(APP_NAME) ?> · 个人知识库</title>
<style>
:root{--bg:#f5f6fa;--panel:#fff;--text:#202536;--muted:#788095;--line:#e8eaf1;--accent:#5c62d6;--soft:#eeefff;--shadow:0 10px 35px rgba(28,35,70,.06)}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif}button,input,textarea,select{font:inherit}a{color:inherit;text-decoration:none;cursor:pointer}.topbar{min-height:68px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 28px;position:sticky;top:0;z-index:5}.brand{font-weight:850;font-size:21px}.brand span{color:var(--accent)}.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.muted{color:var(--muted)}.layout{max-width:1500px;margin:auto;padding:24px;display:grid;grid-template-columns:minmax(285px,370px) minmax(0,1fr);gap:22px}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.editor{padding:20px;align-self:start;position:sticky;top:92px;max-height:calc(100vh - 112px);overflow:auto}.section-title{font-size:16px;font-weight:750;margin:0 0 14px}.field{display:block;width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:#fff;color:var(--text);outline:none;margin:6px 0 13px}.field:focus{border-color:var(--accent);box-shadow:0 0 0 3px #5c62d61a}textarea.field{resize:vertical;min-height:190px}.label{font-size:13px;font-weight:650}.btn{border:0;border-radius:10px;background:var(--accent);color:#fff;padding:9px 13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer}.btn.secondary{background:var(--soft);color:var(--accent)}.btn.ghost{background:#f4f5f9;color:#525a70}.btn.full{width:100%}.checkrow{display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:13px;color:var(--muted)}.content-head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin:0 0 14px}.heading{font-size:25px;font-weight:800;letter-spacing:-.7px;margin:0}.search{display:flex;gap:8px;max-width:460px;width:100%}.search .field{margin:0}.filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.filters .field{width:auto;min-width:135px;margin:0;padding:8px 10px}.note-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.note{padding:18px;min-width:0}.note-title{font-size:17px;font-weight:750;margin:0 0 8px;overflow-wrap:anywhere}.note-content{color:#626b7f;overflow-wrap:anywhere;max-height:135px;overflow:hidden}.note-content p{margin:.35em 0}.note-content pre{overflow:auto;background:#202638;color:#fff;padding:12px;border-radius:8px}.note-content code{background:#f0f1f7;border-radius:4px;padding:2px 4px}.note-content pre code{background:none}.note-content blockquote{border-left:3px solid #8b8fe7;margin:8px 0;padding-left:12px}.tags{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}.tag{background:#f1f2f7;color:#626a7d;border-radius:6px;padding:3px 8px;font-size:12px}.note-foot{border-top:1px solid var(--line);padding-top:11px;margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;color:var(--muted)}.note-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.iconbtn{border:0;background:transparent;color:var(--muted);padding:3px 5px;font-size:12px;cursor:pointer}.iconbtn:hover{color:var(--accent)}.empty{padding:55px 20px;text-align:center;color:var(--muted);grid-column:1/-1}.empty strong{display:block;font-size:18px;color:var(--text);margin-bottom:6px}.alert{padding:11px 13px;border-radius:10px;margin-bottom:14px;background:#fff0f0;color:#a52f2f}.success{padding:11px 13px;border-radius:10px;margin-bottom:14px;background:#edfbf2;color:#24643b}.auth-wrap{min-height:calc(100vh - 100px);display:grid;place-items:center;padding:24px}.auth{width:min(440px,100%);padding:30px}.auth h1{margin-top:0}.hint{font-size:12px;color:var(--muted);margin-top:12px}.footer{text-align:center;color:var(--muted);font-size:12px;padding:10px 20px 28px}.smallform{display:inline}.smallform button{font-size:12px}.editor-tabs{display:flex;gap:6px;margin-bottom:10px}.editor-tabs button{flex:1}.preview{display:none;border:1px solid var(--line);border-radius:10px;padding:12px;min-height:190px;max-height:320px;overflow:auto;background:#fff}.preview.active{display:block}.calendar{padding:15px;margin-bottom:14px}.calendar-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:5px;text-align:center;font-size:12px}.calendar-grid a,.calendar-grid span{padding:5px 0;border-radius:7px}.calendar-grid a{background:#f2f3fa}.calendar-grid a.has-notes{background:#e8e9ff;color:#4c52c2;font-weight:800}.calendar-grid a.selected{background:var(--accent);color:white}.tools{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.tools .panel{padding:12px;flex:1;min-width:210px}.tools h3{font-size:13px;margin:0 0 8px}.tools form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.tools input[type=file]{max-width:180px;font-size:12px}.preview h1,.preview h2,.preview h3{line-height:1.35}.md-space{height:6px}.public-link{font-size:12px;color:#4f55c9;overflow-wrap:anywhere}
@media(max-width:1000px){.layout{grid-template-columns:1fr;padding:15px}.editor{position:static;max-height:none}.note-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.content-head{align-items:flex-start;flex-direction:column}.search{max-width:none}}@media(max-width:600px){.topbar{padding:12px 15px}.layout{padding:10px;gap:13px}.note-grid{grid-template-columns:1fr}.editor{padding:15px}.heading{font-size:22px}.note-foot{align-items:flex-start;flex-direction:column}.tools .panel{min-width:100%}.filters .field{flex:1;min-width:110px}.auth{padding:22px}}
</style></head><body>
<header class="topbar"><a class="brand" href="index.php">e<span>Diary</span></a><div class="top-actions"><?php if(logged_in()): ?><span class="muted"><?= e((string)$_SESSION['username']) ?></span><form method="post" class="smallform"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="btn ghost" type="submit">退出</button></form><?php else: ?><span class="muted">你的私人知识空间</span><?php endif; ?></div></header>
<?php if($setupRequired): ?><section class="auth-wrap"><div class="panel auth"><h1>欢迎使用 eDiary</h1><p class="muted">首次使用，请创建管理员账户。密码至少 12 位。</p><?php if($error!==''): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="setup"><label class="label" for="username">管理员用户名</label><input class="field" id="username" name="username" required minlength="3" maxlength="40" autocomplete="username"><label class="label" for="password">管理员密码</label><input class="field" id="password" type="password" name="password" required minlength="12" autocomplete="new-password"><button class="btn full" type="submit">创建账户并开始使用</button></form><p class="hint">SQLite 文件保存在网站公开目录之外。</p></div></section>
<?php elseif(!logged_in()): ?><section class="auth-wrap"><div class="panel auth"><h1>欢迎回来</h1><p class="muted">登录以继续记录你的想法。</p><?php if($error!==''): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label class="label" for="username">用户名</label><input class="field" id="username" name="username" required autocomplete="username"><label class="label" for="password">密码</label><input class="field" id="password" type="password" name="password" required autocomplete="current-password"><button class="btn full" type="submit">登录</button></form><p class="hint">个人笔记仅在登录后可见。</p></div></section>
<?php else: ?>
<main class="layout"><aside class="panel editor"><h2 class="section-title"><?= $editNote?'编辑笔记':'写一条新笔记' ?></h2><?php if($error!==''): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><?php if($notice!==''): ?><div class="success"><?= e($notice) ?></div><?php endif; ?>
<form method="post" id="noteForm"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" value="<?= (int)($editNote['id']??0) ?>">
<label class="label" for="title">标题</label><input class="field" id="title" name="title" required maxlength="200" placeholder="给这条笔记起个标题" value="<?= e((string)($editNote['title']??'')) ?>">
<label class="label" for="note_type">笔记类型</label><select class="field" id="note_type" name="note_type"><option value="note" <?= ($editNote['note_type']??'note')==='note'?'selected':'' ?>>普通笔记</option><option value="card" <?= ($editNote['note_type']??'')==='card'?'selected':'' ?>>卢曼卡片</option><option value="journal" <?= ($editNote['note_type']??'')==='journal'?'selected':'' ?>>日记</option></select>
<label class="label" for="content">正文（支持 Markdown）</label><div class="editor-tabs"><button type="button" class="btn secondary" id="writeTab">编辑</button><button type="button" class="btn ghost" id="previewTab">实时预览</button></div><textarea class="field" id="content" name="content" placeholder="# 标题&#10;&#10;记录想法、摘录、问题……"><?= e((string)($editNote['content']??'')) ?></textarea><div class="preview" id="preview"></div>
<label class="label" for="tags">标签</label><input class="field" id="tags" name="tags" placeholder="逗号分隔，例如：读书, 灵感, 工作" value="<?= e((string)($editNote['tags']??'')) ?>">
<label class="label" for="journal_date">日记日期（可选）</label><input class="field" type="date" id="journal_date" name="journal_date" value="<?= e((string)($editNote['journal_date']??'')) ?>">
<label class="checkrow"><input type="checkbox" name="is_pinned" value="1" <?= !empty($editNote['is_pinned'])?'checked':'' ?>> 置顶这条笔记</label><label class="checkrow"><input type="checkbox" name="is_public" value="1" <?= !empty($editNote['is_public'])?'checked':'' ?>> 公开发布（任何持有链接的人都能阅读）</label>
<button class="btn full" type="submit"><?= $editNote?'保存修改':'保存笔记' ?></button><?php if($editNote): ?><a class="btn ghost full" style="margin-top:8px" href="index.php">新建笔记</a><?php endif; ?></form>
<?php if($editNote && !empty($editNote['is_public'])): ?><p class="hint">公开链接：<a class="public-link" target="_blank" rel="noopener" href="<?= e('index.php?public='.$editNote['slug']) ?>"><?= e('index.php?public='.$editNote['slug']) ?></a></p><?php endif; ?>
<?php if($editNote && ($editNote['note_type']??'')==='card'): ?><hr style="border:0;border-top:1px solid var(--line);margin:18px 0"><h3 class="section-title">反向链接（<?= count($backlinks) ?>）</h3><?php if($backlinks): ?><ul><?php foreach($backlinks as $link): ?><li><a href="index.php?edit=<?= (int)$link['id'] ?>"><?= e($link['title']) ?></a></li><?php endforeach; ?></ul><?php else: ?><p class="hint">在其他笔记中写入 <code>[[<?= e($editNote['title']) ?>]]</code>，即可建立链接。</p><?php endif; ?><?php endif; ?>
<p class="hint">支持 Markdown、[[笔记标题]] 卢曼卡片链接。未勾选公开发布的笔记始终保持私密。</p></aside>
<section><div class="content-head"><div><h1 class="heading">我的知识库</h1><div class="muted"><?= count($notes) ?> 条笔记 · 普通笔记、卡片与日记</div></div><form class="search" method="get"><input class="field" name="q" placeholder="搜索标题、正文或标签…" value="<?= e($query) ?>"><button class="btn secondary" type="submit">搜索</button><?php if($query!==''||$tagFilter!==''||$dateFilter!==''||$typeFilter!==''): ?><a class="btn ghost" href="index.php">清除</a><?php endif; ?></form></div>
<div class="tools"><div class="panel"><h3>数据导出 / 导入</h3><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn secondary" name="action" value="export_json">导出 JSON</button></form><form method="post" enctype="multipart/form-data" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_json"><input type="file" name="import_file" accept=".json,application/json" required><button class="btn secondary" type="submit">导入 JSON</button></form></div><div class="panel"><h3>数据库备份</h3><form method="post" onsubmit="return confirm('创建一份完整 SQLite 数据库备份？')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn secondary" name="action" value="backup_db">创建数据库备份</button></form><p class="hint">备份保存在网站根目录之外的 ediary-data/backups。</p></div></div>
<form class="filters" method="get"><select class="field" name="tag"><option value="">全部标签</option><?php foreach($tagsAvailable as $tag): ?><option value="<?= e($tag) ?>" <?= $tagFilter===$tag?'selected':'' ?>><?= e($tag) ?></option><?php endforeach; ?></select><input class="field" type="date" name="date" value="<?= e($dateFilter) ?>" title="按日期筛选"><select class="field" name="type"><option value="">全部类型</option><option value="note" <?= $typeFilter==='note'?'selected':'' ?>>普通笔记</option><option value="card" <?= $typeFilter==='card'?'selected':'' ?>>卢曼卡片</option><option value="journal" <?= $typeFilter==='journal'?'selected':'' ?>>日记</option></select><button class="btn secondary" type="submit">筛选</button></form>
<div class="panel calendar"><div class="calendar-head"><strong>日记日历</strong><span class="muted"><?= e(date('Y年m月')) ?></span></div><div class="calendar-grid"><?php foreach(['一','二','三','四','五','六','日'] as $wd): ?><span class="muted"><?= $wd ?></span><?php endforeach; ?><?php $firstDay=(int)date('N',strtotime(date('Y-m-01'))); $days=(int)date('t'); for($i=1;$i<$firstDay;$i++) echo '<span></span>'; for($day=1;$day<=$days;$day++): $d=date('Y-m-').sprintf('%02d',$day); $has=isset($calendarCounts[$d]); ?><a class="<?= ($has?'has-notes ':'').($dateFilter===$d?'selected':'') ?>" href="index.php?date=<?= e($d) ?>" title="<?= $has?(int)$calendarCounts[$d].' 条笔记':'筛选此日期' ?>"><?= $day ?></a><?php endfor; ?></div></div>
<div class="note-grid"><?php foreach($notes as $note): ?><article class="panel note"><h2 class="note-title"><?= !empty($note['is_pinned'])?'📌 ':'' ?><?php if(($note['note_type']??'')==='card'): ?>🗂️ <?php elseif(($note['note_type']??'')==='journal'): ?>📅 <?php endif; ?><?= e($note['title']) ?></h2><div class="note-content"><?= markdown_html((string)$note['content']) ?></div><div class="tags"><?php foreach(parse_tags((string)$note['tags']) as $tag): ?><a class="tag" href="index.php?tag=<?= rawurlencode($tag) ?>"><?= e($tag) ?></a><?php endforeach; ?><?php if(!empty($note['is_public'])): ?><span class="tag">已公开</span><?php endif; ?></div><div class="note-foot"><span><?= e(substr((string)($note['journal_date']?:$note['created_at']),0,10)) ?></span><div class="note-actions"><?php if(!empty($note['is_public'])): ?><a class="iconbtn" target="_blank" rel="noopener" href="<?= e('index.php?public='.$note['slug']) ?>">查看公开页</a><?php endif; ?><a class="iconbtn" href="index.php?edit=<?= (int)$note['id'] ?>">编辑</a><form method="post" class="smallform"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="id" value="<?= (int)$note['id'] ?>"><button class="iconbtn" type="submit"><?= !empty($note['is_pinned'])?'取消置顶':'置顶' ?></button></form><form method="post" class="smallform" onsubmit="return confirm('确定删除这条笔记吗？此操作不可撤销。')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_note"><input type="hidden" name="id" value="<?= (int)$note['id'] ?>"><button class="iconbtn" type="submit">删除</button></form></div></div></article><?php endforeach; ?><?php if(!$notes): ?><div class="panel empty"><strong><?= $query!==''?'没有找到匹配的笔记':'从第一条笔记开始' ?></strong><span>调整筛选条件，或在左侧新建一条笔记。</span></div><?php endif; ?></div>
</section></main><?php endif; ?><footer class="footer">eDiary · PHP + SQLite · 私密优先的个人知识库</footer>
<script>
(function(){
const area=document.getElementById('content'), preview=document.getElementById('preview'), writeTab=document.getElementById('writeTab'), previewTab=document.getElementById('previewTab');
if(!area||!preview)return;
function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')}
function render(s){
 let blocks=[], text=s.replace(/\r\n?/g,'\n');
 text=text.replace(/\x60\x60\x60([^\n]*)\n([\s\S]*?)\x60\x60\x60/g,(_,lang,code)=>{blocks.push('<pre><code>'+esc(code)+'</code></pre>');return '\u0000'+(blocks.length-1)+'\u0000'});
 let html=esc(text).replace(/\x60([^\x60\n]+)\x60/g,'<code>$1</code>').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\*([^*\n]+)\*/g,'<em>$1</em>');
 html=html.split('\n').map(line=>{let m;if((m=line.match(/^(#{1,4})\s+(.+)$/)))return '<h'+m[1].length+'>'+m[2]+'</h'+m[1].length+'>';if((m=line.match(/^\s*[-*]\s+(.+)$/)))return '<li>'+m[1]+'</li>';if((m=line.match(/^&gt;\s?(.*)$/)))return '<blockquote>'+m[1]+'</blockquote>';return line.trim()?'<p>'+line+'</p>':'<div class="md-space"></div>'}).join('\n');
 html=html.replace(/\u0000(\d+)\u0000/g,(_,i)=>blocks[Number(i)]||'');return html;
}
function update(){preview.innerHTML=render(area.value)}
area.addEventListener('input',update);
if(writeTab&&previewTab){previewTab.addEventListener('click',()=>{update();area.style.display='none';preview.classList.add('active');previewTab.className='btn secondary';writeTab.className='btn ghost'});writeTab.addEventListener('click',()=>{area.style.display='block';preview.classList.remove('active');writeTab.className='btn secondary';previewTab.className='btn ghost'})}
})();
</script></body></html>
