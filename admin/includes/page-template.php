<?php
/**
 * 智能GEO内容系统 - 页面模板
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database_admin.php';
require_once __DIR__ . '/../../includes/functions.php';

// 检查管理员登录
require_admin_login();

$message = '';
$error = '';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'example_action':
                // 处理示例操作
                $message = '操作成功';
                break;
        }
    }
}

// 设置页面信息
$page_title = '页面标题';
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">页面标题</h1>
        <p class="mt-1 text-sm text-gray-600">页面描述</p>
    </div>
    <div class="flex space-x-3">
        <button class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            主要操作
        </button>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/header.php';
?>

        <!-- 页面内容开始 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">内容标题</h3>
            </div>
            <div class="px-6 py-6">
                <p class="text-gray-600">页面内容...</p>
            </div>
        </div>
        <!-- 页面内容结束 -->

        <!-- 示例模态框 -->
        <div id="example-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">模态框标题</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="example_action">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">字段标签 *</label>
                                <input type="text" name="field_name" required 
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="请输入内容">
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" data-action-call="hideModal" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                取消
                            </button>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                确定
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

<?php
// 设置额外的JavaScript
$additional_js = '
<script>
    // 页面特定的JavaScript代码
    function showModal() {
        document.getElementById("example-modal").classList.remove("hidden");
    }

    function hideModal() {
        document.getElementById("example-modal").classList.add("hidden");
    }

    // 点击模态框外部关闭
    document.addEventListener("click", function(event) {
        const modal = document.getElementById("example-modal");
        if (event.target === modal) {
            hideModal();
        }
    });
</script>
';

// 包含底部模块
require_once __DIR__ . '/footer.php';
?>
