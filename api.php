<?php
/**
 * 嘉禾云网 - 数据API接口
 * 功能：
 * 1. 自动保存：将后台提交的数据保存为同目录下的 site_data.json
 * 2. 数据读取：刷新页面时自动加载服务器上的最新数据
 * 3. 跨域支持：允许不同环境下（如调试环境）的正常通信
 * 4. 权限检测：如果文件夹不可写，会返回明确的错误提示
 */

// ============ 跨域支持配置 ============
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============ 配置参数 ============
$dataFile = __DIR__ . '/site_data.json';
$backupDir = __DIR__ . '/backups';
$maxBackups = 10; // 最多保留10个备份文件

// ============ 权限检测函数 ============
function checkPermissions($file, $dir) {
    $errors = [];
    
    // 检查目录是否可写
    if (!is_writable($dir)) {
        $errors[] = "目录不可写: $dir (当前权限: " . substr(sprintf('%o', fileperms($dir)), -4) . ")";
    }
    
    // 如果文件存在，检查文件是否可写
    if (file_exists($file) && !is_writable($file)) {
        $errors[] = "文件不可写: $file (当前权限: " . substr(sprintf('%o', fileperms($file)), -4) . ")";
    }
    
    return $errors;
}

// ============ 备份函数 ============
function backupDataFile($sourceFile, $backupDir, $maxBackups) {
    if (!file_exists($sourceFile)) {
        return;
    }
    
    // 创建备份目录
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // 生成备份文件名（带时间戳）
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/site_data_' . $timestamp . '.json';
    
    // 复制文件
    copy($sourceFile, $backupFile);
    
    // 清理旧备份（保留最新的N个）
    $backupFiles = glob($backupDir . '/site_data_*.json');
    if (count($backupFiles) > $maxBackups) {
        // 按修改时间排序
        usort($backupFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // 删除最旧的备份
        $filesToDelete = array_slice($backupFiles, 0, count($backupFiles) - $maxBackups);
        foreach ($filesToDelete as $file) {
            unlink($file);
        }
    }
}

// ============ 日志函数 ============
function logOperation($operation, $success, $message = '') {
    $logFile = __DIR__ . '/api_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'ERROR';
    $logEntry = "[$timestamp] [$status] $operation";
    if ($message) {
        $logEntry .= " - $message";
    }
    $logEntry .= "\n";
    
    // 追加日志（忽略错误，避免日志写入失败影响主功能）
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// ============ GET 请求：读取数据 ============
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // 检查数据文件是否存在
        if (file_exists($dataFile)) {
            // 读取文件内容
            $jsonContent = file_get_contents($dataFile);
            
            // 验证JSON格式
            $data = json_decode($jsonContent, true);
            
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                // JSON解析失败
                logOperation('READ', false, 'JSON解析失败: ' . json_last_error_msg());
                
                http_response_code(500);
                echo json_encode([
                    'error' => true,
                    'message' => 'JSON文件格式错误: ' . json_last_error_msg()
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 成功返回数据
            logOperation('READ', true, '成功读取数据');
            echo $jsonContent;
        } else {
            // 文件不存在，返回空对象（前端会使用默认值）
            logOperation('READ', true, '数据文件不存在，返回空对象');
            echo json_encode([
                'message' => '数据文件不存在，将使用默认配置'
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        logOperation('READ', false, $e->getMessage());
        
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => '读取数据失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============ POST 请求：保存数据 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 权限检测
        $permissionErrors = checkPermissions($dataFile, __DIR__);
        if (!empty($permissionErrors)) {
            logOperation('WRITE', false, implode('; ', $permissionErrors));
            
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'permission_denied',
                'message' => '权限不足，无法保存数据',
                'details' => $permissionErrors,
                'suggestions' => [
                    '1. 请确保目录权限为 755 或 777',
                    '2. 执行命令: chmod 755 ' . __DIR__,
                    '3. 如果文件已存在: chmod 644 ' . $dataFile,
                    '4. 确保Web服务器用户(www-data/apache)有写入权限'
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 读取POST数据
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            logOperation('WRITE', false, 'POST数据为空');
            
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'empty_data',
                'message' => '未接收到数据'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 验证JSON格式
        $data = json_decode($rawInput, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            logOperation('WRITE', false, 'JSON格式错误: ' . json_last_error_msg());
            
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'invalid_json',
                'message' => 'JSON格式错误: ' . json_last_error_msg()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 备份现有数据（如果存在）
        if (file_exists($dataFile)) {
            backupDataFile($dataFile, $backupDir, $maxBackups);
        }
        
        // 格式化JSON（美化输出，便于人工查看）
        $formattedJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // 写入文件
        $bytesWritten = file_put_contents($dataFile, $formattedJson);
        
        if ($bytesWritten === false) {
            logOperation('WRITE', false, '文件写入失败');
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'write_failed',
                'message' => '文件写入失败，请检查目录权限'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 设置文件权限（确保可读写）
        @chmod($dataFile, 0644);
        
        // 成功响应
        logOperation('WRITE', true, "成功写入 $bytesWritten 字节");
        
        echo json_encode([
            'success' => true,
            'message' => '数据保存成功',
            'file' => basename($dataFile),
            'size' => $bytesWritten,
            'timestamp' => date('Y-m-d H:i:s'),
            'backup_created' => file_exists($backupDir)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        logOperation('WRITE', false, $e->getMessage());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'exception',
            'message' => '保存失败: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============ 其他请求方法不支持 ============
http_response_code(405);
echo json_encode([
    'error' => true,
    'message' => '不支持的请求方法: ' . $_SERVER['REQUEST_METHOD'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS']
], JSON_UNESCAPED_UNICODE);
