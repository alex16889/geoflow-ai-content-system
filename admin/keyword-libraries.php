<?php
/**
 * 智能GEO内容系统 - 关键词库管理
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$message = '';
$error = '';
$currentSiteId = geoflow_current_site_id();
$keywordLibrariesSiteCondition = geoflow_site_scope_condition('keyword_libraries');
$keywordLibrariesAliasSiteCondition = geoflow_site_scope_condition('keyword_libraries', 'kl');

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_library':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $error = __('keyword_libraries.error.name_required');
                } else {
                    try {
                        if (geoflow_table_has_site_column($db, 'keyword_libraries')) {
                            $stmt = $db->prepare("
                                INSERT INTO keyword_libraries (site_id, name, description, keyword_count, created_at, updated_at) 
                                VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $params = [$currentSiteId, $name, $description];
                        } else {
                            $stmt = $db->prepare("
                                INSERT INTO keyword_libraries (name, description, keyword_count, created_at, updated_at) 
                                VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $params = [$name, $description];
                        }
                        
                        if ($stmt->execute($params)) {
                            $message = __('keyword_libraries.message.create_success');
                        } else {
                            $error = __('keyword_libraries.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('keyword_libraries.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_library':
                $library_id = intval($_POST['library_id'] ?? 0);
                
                if ($library_id > 0) {
                    try {
                        $db->beginTransaction();

                        $libraryStmt = $db->prepare("SELECT id FROM keyword_libraries WHERE id = ?" . ($keywordLibrariesSiteCondition !== '' ? ' AND ' . $keywordLibrariesSiteCondition : ''));
                        $libraryStmt->execute([$library_id]);
                        if (!$libraryStmt->fetchColumn()) {
                            throw new RuntimeException(__('keyword_libraries.error.library_required'));
                        }
                        
                        // 删除关键词库中的所有关键词
                        $stmt = $db->prepare("DELETE FROM keywords WHERE library_id = ?");
                        $stmt->execute([$library_id]);
                        
                        // 删除关键词库
                        $stmt = $db->prepare("DELETE FROM keyword_libraries WHERE id = ?");
                        $stmt->execute([$library_id]);
                        
                        $db->commit();
                        $message = __('keyword_libraries.message.delete_success');
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = __('keyword_libraries.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'import_keywords':
                $library_id = intval($_POST['library_id'] ?? 0);
                $keywords_text = trim($_POST['keywords_text'] ?? '');
                $import_type = $_POST['import_type'] ?? 'text';
                
                if ($library_id <= 0) {
                    $error = __('keyword_libraries.error.library_required');
                } elseif (empty($keywords_text) && $import_type === 'text') {
                    $error = __('keyword_libraries.error.keywords_required');
                } else {
                    try {
                        $db->beginTransaction();

                        $libraryStmt = $db->prepare("SELECT id FROM keyword_libraries WHERE id = ?" . ($keywordLibrariesSiteCondition !== '' ? ' AND ' . $keywordLibrariesSiteCondition : ''));
                        $libraryStmt->execute([$library_id]);
                        if (!$libraryStmt->fetchColumn()) {
                            throw new RuntimeException(__('keyword_libraries.error.library_required'));
                        }
                        
                        $keywords = $import_type === 'text' ? material_keyword_rows_from_text($keywords_text) : [];
                        $importResult = material_import_keywords($db, $library_id, $keywords, ['source' => 'manual']);
                        $imported_count = (int) $importResult['imported'];
                        $duplicate_count = (int) $importResult['duplicate'];
                        
                        $db->commit();
                        $message = __('keyword_libraries.message.import_success', ['count' => $imported_count]);
                        if ($duplicate_count > 0) {
                            $message .= __('keyword_libraries.message.import_skip', ['count' => $duplicate_count]);
                        }
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = __('keyword_libraries.message.import_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取关键词库列表
$libraries = $db->query("
    SELECT kl.*, 
           (SELECT COUNT(*) FROM keywords WHERE library_id = kl.id) as actual_count
    FROM keyword_libraries kl 
    " . ($keywordLibrariesAliasSiteCondition !== '' ? 'WHERE ' . $keywordLibrariesAliasSiteCondition : '') . "
    ORDER BY kl.created_at DESC
")->fetchAll();

// 获取统计数据
$stats = [
    'total_libraries' => count($libraries),
    'total_keywords' => $db->query("SELECT COUNT(*) as count FROM keywords k INNER JOIN keyword_libraries kl ON kl.id = k.library_id" . ($keywordLibrariesAliasSiteCondition !== '' ? ' WHERE ' . $keywordLibrariesAliasSiteCondition : ''))->fetch()['count'],
    'avg_keywords' => count($libraries) > 0 ? round($db->query("SELECT COUNT(*) as count FROM keywords k INNER JOIN keyword_libraries kl ON kl.id = k.library_id" . ($keywordLibrariesAliasSiteCondition !== '' ? ' WHERE ' . $keywordLibrariesAliasSiteCondition : ''))->fetch()['count'] / count($libraries), 1) : 0
];

// 设置页面信息
$page_title = __('keyword_libraries.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('keyword_libraries.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('keyword_libraries.subtitle') . '</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="keyword-research.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700">
            <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
            DataForSEO 拉词
        </a>
        <button type="button" data-action-call="showCreateModal" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            ' . __('keyword_libraries.create') . '
        </button>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

        <?php if ($message): ?>
            <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>


        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="folder" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_libraries.total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_libraries']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_libraries.total_keywords'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_keywords']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.avg_per_library'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['avg_keywords']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 关键词库列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('keyword_libraries.list_title'); ?></h3>
            </div>

            <?php if (empty($libraries)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('keyword_libraries.empty'); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo __('keyword_libraries.empty_desc'); ?></p>
                    <button type="button" data-action-call="showCreateModal" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        <?php echo __('keyword_libraries.create'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($libraries as $library): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="keyword-library-detail.php?id=<?php echo $library['id']; ?>" class="hover:text-blue-600">
                                                <?php echo htmlspecialchars($library['name']); ?>
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo __('keyword_libraries.keyword_count', ['count' => $library['actual_count']]); ?>
                                        </span>
                                    </div>
                                    <?php if ($library['description']): ?>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($library['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?php echo __('keyword_libraries.created_at', ['value' => date('Y-m-d H:i', strtotime($library['created_at']))]); ?></span>
                                        <span><?php echo __('keyword_libraries.updated_at', ['value' => date('Y-m-d H:i', strtotime($library['updated_at']))]); ?></span>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <button type="button" data-action-call="showImportModal" data-action-args='<?php echo htmlspecialchars(json_encode([(int) $library['id'], (string) $library['name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>' class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.import'); ?>
                                    </button>
                                    <a href="keyword-library-detail.php?id=<?php echo $library['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.view'); ?>
                                    </a>
                                    <button type="button" data-action-call="deleteLibrary" data-action-args='<?php echo htmlspecialchars(json_encode([(int) $library['id'], (string) $library['name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>' class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.delete'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 创建关键词库模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('keyword_libraries.modal_create'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_library">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_libraries.field_name'); ?></label>
                            <input type="text" name="name" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('keyword_libraries.placeholder_name')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_libraries.field_description'); ?></label>
                            <textarea name="description" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('keyword_libraries.placeholder_description')); ?>"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" data-action-call="hideCreateModal" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <?php echo __('button.create'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 导入关键词模态框 -->
    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('keyword_libraries.modal_import'); ?> <span id="import-library-name" class="text-blue-600"></span></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="import_keywords">
                    <input type="hidden" name="library_id" id="import-library-id">
                    <input type="hidden" name="import_type" value="text">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_libraries.field_keywords'); ?></label>
                            <textarea name="keywords_text" rows="10" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('keyword_libraries.placeholder_keywords')); ?>"></textarea>
                        </div>
                        
                        <div class="text-sm text-gray-500">
                            <p class="mb-2"><?php echo __('keyword_libraries.format_title'); ?></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li><?php echo __('keyword_libraries.format_line'); ?></li>
                                <li><?php echo __('keyword_libraries.format_comma'); ?></li>
                                <li><?php echo __('keyword_libraries.format_dedupe'); ?></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" data-action-call="hideImportModal" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <?php echo __('keyword_libraries.import_button'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 显示创建模态框
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        // 隐藏创建模态框
        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        // 显示导入模态框
        function showImportModal(libraryId, libraryName) {
            document.getElementById('import-library-id').value = libraryId;
            document.getElementById('import-library-name').textContent = libraryName;
            document.getElementById('import-modal').classList.remove('hidden');
        }

        // 隐藏导入模态框
        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        // 删除关键词库
        function deleteLibrary(libraryId, libraryName) {
            if (confirm(`<?php echo __('keyword_libraries.confirm_delete', ['name' => '{name}']); ?>`.replace('{name}', libraryName))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_library">
                    <input type="hidden" name="library_id" value="${libraryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        document.addEventListener('click', function(event) {
            const createModal = document.getElementById('create-modal');
            const importModal = document.getElementById('import-modal');
            
            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === importModal) {
                hideImportModal();
            }
        });
    </script>
</body>
</html>
