<?php
/**
 * 成员管理 API
 */

require_once '../config.php';
require_once '../bot/TelegramBot.php';
checkLogin();

$db = getDB();
$bot = new TelegramBot(BOT_TOKEN);

// GET 请求
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    $group_id = $_GET['group_id'] ?? 0;
    
    if ($action == 'list') {
        try {
            $query = "SELECT gm.*, g.title as group_title FROM group_members gm JOIN groups g ON gm.group_id = g.id";
            
            if ($group_id) {
                $stmt = $db->prepare($query . " WHERE gm.group_id = ? ORDER BY gm.id DESC");
                $stmt->execute([$group_id]);
            } else {
                $stmt = $db->query($query . " ORDER BY gm.id DESC");
            }
            
            $members = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $members]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch members error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
    
    // 导出成员
    if ($action == 'export') {
        $format = $_GET['format'] ?? 'csv';
        
        try {
            $query = "SELECT gm.user_id, gm.username, gm.first_name, gm.last_name, gm.status, gm.joined_at, g.title as group_title, g.chat_id 
                      FROM group_members gm 
                      JOIN groups g ON gm.group_id = g.id";
            
            if ($group_id) {
                $stmt = $db->prepare($query . " WHERE gm.group_id = ? ORDER BY gm.id DESC");
                $stmt->execute([$group_id]);
            } else {
                $stmt = $db->query($query . " ORDER BY gm.id DESC");
            }
            
            $members = $stmt->fetchAll();
            
            // 获取群组名称用于文件名
            $group_name = '所有群组';
            if ($group_id) {
                $stmt = $db->prepare("SELECT title FROM groups WHERE id = ?");
                $stmt->execute([$group_id]);
                $group = $stmt->fetch();
                if ($group) {
                    $group_name = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}]/u', '_', $group['title']);
                }
            }
            
            $filename = "members_{$group_name}_" . date('Y-m-d_His');
            
            switch ($format) {
                case 'csv':
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                    
                    // 添加 BOM 以支持 Excel 打开中文
                    echo "\xEF\xBB\xBF";
                    
                    // CSV 头部
                    echo "用户ID,用户名,名字,姓氏,群组,群组ID,状态,加入时间\n";
                    
                    foreach ($members as $m) {
                        $username = $m['username'] ? '@' . $m['username'] : '';
                        $row = [
                            $m['user_id'],
                            $username,
                            $m['first_name'] ?? '',
                            $m['last_name'] ?? '',
                            $m['group_title'],
                            $m['chat_id'],
                            $m['status'],
                            $m['joined_at']
                        ];
                        // 处理 CSV 转义
                        $row = array_map(function($v) {
                            if (strpos($v, ',') !== false || strpos($v, '"') !== false || strpos($v, "\n") !== false) {
                                return '"' . str_replace('"', '""', $v) . '"';
                            }
                            return $v;
                        }, $row);
                        echo implode(',', $row) . "\n";
                    }
                    break;
                    
                case 'txt':
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
                    
                    echo "====================================\n";
                    echo "群成员导出报告\n";
                    echo "导出时间: " . date('Y-m-d H:i:s') . "\n";
                    echo "群组: " . $group_name . "\n";
                    echo "总人数: " . count($members) . "\n";
                    echo "====================================\n\n";
                    
                    foreach ($members as $index => $m) {
                        $num = $index + 1;
                        $username = $m['username'] ? '@' . $m['username'] : '无';
                        $fullname = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                        
                        echo "【{$num}】\n";
                        echo "  用户ID: {$m['user_id']}\n";
                        echo "  用户名: {$username}\n";
                        echo "  姓名: {$fullname}\n";
                        echo "  群组: {$m['group_title']}\n";
                        echo "  状态: {$m['status']}\n";
                        echo "  加入时间: {$m['joined_at']}\n";
                        echo "------------------------------------\n";
                    }
                    break;
                    
                case 'json':
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                    
                    $export_data = [
                        'export_time' => date('Y-m-d H:i:s'),
                        'group_name' => $group_name,
                        'total_count' => count($members),
                        'members' => array_map(function($m) {
                            return [
                                'user_id' => $m['user_id'],
                                'username' => $m['username'] ? '@' . $m['username'] : null,
                                'first_name' => $m['first_name'],
                                'last_name' => $m['last_name'],
                                'full_name' => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
                                'group_title' => $m['group_title'],
                                'group_chat_id' => $m['chat_id'],
                                'status' => $m['status'],
                                'joined_at' => $m['joined_at']
                            ];
                        }, $members)
                    ];
                    
                    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    break;
                    
                default:
                    jsonResponse(['success' => false, 'message' => '不支持的格式'], 400);
            }
            
            exit;
        } catch (Exception $e) {
            logSystem('error', 'Export members error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '导出失败: ' . $e->getMessage()], 500);
        }
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'kick':
            $member_id = $data['member_id'] ?? 0;
            
            if (!$member_id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("SELECT gm.user_id, g.chat_id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch();
                
                if (!$member) {
                    jsonResponse(['success' => false, 'message' => '成员不存在'], 404);
                }
                
                $bot->kickChatMember($member['chat_id'], $member['user_id']);
                
                $stmt = $db->prepare("UPDATE group_members SET status = 'kicked' WHERE id = ?");
                $stmt->execute([$member_id]);
                
                logSystem('info', 'Kicked member', ['member_id' => $member_id]);
                jsonResponse(['success' => true, 'message' => '已踢出成员']);
            } catch (Exception $e) {
                logSystem('error', 'Kick member error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '操作失败'], 500);
            }
            break;
            
        case 'ban':
            $member_id = $data['member_id'] ?? 0;
            
            if (!$member_id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("SELECT gm.user_id, g.chat_id, g.id as group_id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch();
                
                if (!$member) {
                    jsonResponse(['success' => false, 'message' => '成员不存在'], 404);
                }
                
                $bot->kickChatMember($member['chat_id'], $member['user_id'], 0);
                
                // 添加到黑名单
                $stmt = $db->prepare("INSERT INTO blacklist (user_id, group_id, reason, banned_by) VALUES (?, ?, '后台封禁', ?)");
                $stmt->execute([$member['user_id'], $member['group_id'], $_SESSION['admin_id']]);
                
                logSystem('info', 'Banned member', ['member_id' => $member_id]);
                jsonResponse(['success' => true, 'message' => '已封禁成员']);
            } catch (Exception $e) {
                logSystem('error', 'Ban member error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '操作失败'], 500);
            }
            break;
            
        case 'mute':
            $member_id = $data['member_id'] ?? 0;
            $duration = $data['duration'] ?? 3600; // 默认1小时
            
            if (!$member_id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("SELECT gm.user_id, g.chat_id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch();
                
                if (!$member) {
                    jsonResponse(['success' => false, 'message' => '成员不存在'], 404);
                }
                
                $bot->restrictChatMember($member['chat_id'], $member['user_id'], time() + $duration);
                
                logSystem('info', 'Muted member', ['member_id' => $member_id, 'duration' => $duration]);
                jsonResponse(['success' => true, 'message' => '已禁言成员']);
            } catch (Exception $e) {
                logSystem('error', 'Mute member error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '操作失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

