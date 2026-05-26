<?php
/**
 * 管理员设置模态框组件
 */

// 获取当前管理员信息
$current_admin = get_admin_info($_SESSION['admin_id']);
?>

<!-- 管理员设置模态框 -->
<div id="adminSettingsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <!-- 模态框头部 -->
            <div class="flex items-center justify-between pb-3 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">管理员设置</h3>
                <button type="button" data-action-call="closeAdminSettings" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <!-- 模态框内容 -->
            <div class="mt-4">
                <form id="adminSettingsForm" method="POST" action="settings.php" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="admin">
                    
                    <!-- 当前信息显示 -->
                    <div class="bg-gray-50 p-3 rounded-md">
                        <div class="text-sm text-gray-600">
                            <p><strong>当前用户名：</strong><?php echo htmlspecialchars($current_admin['username']); ?></p>
                            <p><strong>创建时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($current_admin['created_at'])); ?></p>
                        </div>
                    </div>
                    
                    <!-- 用户名输入 -->
                    <div>
                        <label for="modal_admin_username" class="block text-sm font-medium text-gray-700 mb-2">
                            管理员用户名
                        </label>
                        <input 
                            type="text" 
                            id="modal_admin_username" 
                            name="admin_username" 
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            value="<?php echo htmlspecialchars($current_admin['username']); ?>"
                            required
                        >
                        <p class="mt-1 text-xs text-gray-500">用于登录管理后台的用户名</p>
                    </div>
                    
                    <!-- 新密码输入 -->
                    <div>
                        <label for="modal_admin_password" class="block text-sm font-medium text-gray-700 mb-2">
                            新密码
                        </label>
                        <input 
                            type="password" 
                            id="modal_admin_password" 
                            name="admin_password" 
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            minlength="8"
                            placeholder="至少 8 位，建议包含大小写字母、数字和特殊字符"
                        >
                        <p class="mt-1 text-xs text-gray-500">留空则不修改；填写时至少 8 位并满足复杂度要求</p>
                    </div>
                    
                    <!-- 确认密码输入 -->
                    <div>
                        <label for="modal_confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                            确认新密码
                        </label>
                        <input 
                            type="password" 
                            id="modal_confirm_password" 
                            name="confirm_password" 
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            minlength="8"
                            placeholder="再次输入新密码"
                        >
                    </div>
                    
                    <!-- 按钮组 -->
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" data-action-call="closeAdminSettings" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition-colors">
                            取消
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">
                            更新信息
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// 管理员设置模态框相关JavaScript函数
function openAdminSettings() {
    document.getElementById('adminSettingsModal').classList.remove('hidden');
}

function closeAdminSettings() {
    document.getElementById('adminSettingsModal').classList.add('hidden');
}

// 点击模态框外部关闭
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('adminSettingsModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAdminSettings();
            }
        });
    }
});
</script>
