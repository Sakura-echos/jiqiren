/**
 * Telegram Bot 管理后台 JavaScript
 */

// 全局工具函数
const App = {
    // API 请求
    async request(url, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);
            const contentType = response.headers.get('content-type');
            
            // 检查响应类型
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('服务器返回了非JSON格式的数据');
            }
            
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || '请求失败');
            }
            
            return result;
        } catch (error) {
            console.error('Request error:', error);
            this.showAlert(error.message || '请求失败，请重试', 'error');
            return null;
        }
    },

    // 显示提示信息
    showAlert(message, type = 'success') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;
        alertDiv.style.position = 'fixed';
        alertDiv.style.top = '20px';
        alertDiv.style.right = '20px';
        alertDiv.style.zIndex = '9999';
        alertDiv.style.minWidth = '250px';
        
        document.body.appendChild(alertDiv);

        setTimeout(() => {
            alertDiv.remove();
        }, 3000);
    },

    // 确认对话框
    confirm(message) {
        return window.confirm(message);
    },

    // 格式化日期
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('zh-CN');
    },

    // 模态框控制
    showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            // 如果是添加违禁词的模态框，重置表单
            if (modalId === 'addWordModal') {
                const form = modal.querySelector('form');
                if (form) {
                    form.reset();
                    // 确保删除消息选项默认选中
                    const deleteMessageCheckbox = form.querySelector('#newDeleteMessage');
                    if (deleteMessageCheckbox) {
                        deleteMessageCheckbox.checked = true;
                    }
                    // 确保匹配方式默认为精确匹配
                    const matchTypeSelect = form.querySelector('#newMatchType');
                    if (matchTypeSelect) {
                        matchTypeSelect.value = 'exact';
                    }
                }
            }
            modal.classList.add('show');
        }
    },

    hideModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
        }
    }
};

// 登录功能
if (document.getElementById('loginForm')) {
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        
        const result = await App.request('api/auth.php', 'POST', {
            action: 'login',
            username: username,
            password: password
        });
        
        if (result && result.success) {
            window.location.href = 'dashboard.php';
        } else {
            App.showAlert(result?.message || '登录失败', 'error');
        }
    });
}

// 违禁词管理
const BannedWords = {
    async load() {
        const result = await App.request('api/banned_words.php?action=list');
        if (result && result.success) {
            this.render(result.data);
        }
    },

    render(words) {
        const tbody = document.getElementById('bannedWordsTable');
        if (!tbody) return;

        if (words.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-state">暂无违禁词</td></tr>';
            return;
        }

        tbody.innerHTML = words.map(word => {
            const actions = [];
            if (word.delete_message) actions.push('删除消息');
            if (word.warn_user) actions.push('警告');
            if (word.kick_user) actions.push('踢出');
            if (word.ban_user) actions.push('封禁');
            
            const matchTypeText = {
                'exact': '精确匹配',
                'contains': '包含匹配',
                'starts_with': '开头匹配',
                'ends_with': '结尾匹配',
                'regex': '正则表达式'
            }[word.match_type] || word.match_type;

            return `
            <tr>
                <td>${word.id}</td>
                <td>${word.word}</td>
                <td><span class="badge badge-info">${matchTypeText}</span></td>
                <td>${word.group_title || '所有群组'}</td>
                <td>${actions.map(action => `<span class="badge badge-primary">${action}</span>`).join(' ')}</td>
                <td><span class="badge badge-${word.is_active ? 'success' : 'danger'}">${word.is_active ? '启用' : '禁用'}</span></td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="BannedWords.delete(${word.id})">删除</button>
                </td>
            </tr>
        `}).join('');
    },

    async add() {
        try {
            const word = document.getElementById('newWord')?.value;
            const groupId = document.getElementById('newGroupId')?.value;
            const deleteMessage = document.getElementById('newDeleteMessage')?.checked ?? true;
            const warnUser = document.getElementById('newWarnUser')?.checked ?? false;
            const kickUser = document.getElementById('newKickUser')?.checked ?? false;
            const banUser = document.getElementById('newBanUser')?.checked ?? false;
            const matchType = document.getElementById('newMatchType')?.value ?? 'exact';

            if (!word) {
                App.showAlert('请输入违禁词', 'error');
                return;
            }

            if (!deleteMessage && !warnUser && !kickUser && !banUser) {
                App.showAlert('请至少选择一个处理动作', 'error');
                return;
            }

            const result = await App.request('api/banned_words.php', 'POST', {
            action: 'add',
            word: word,
            group_id: groupId || null,
            delete_message: deleteMessage ? 1 : 0,
            warn_user: warnUser ? 1 : 0,
            kick_user: kickUser ? 1 : 0,
            ban_user: banUser ? 1 : 0,
            match_type: matchType
        });

            if (result && result.success) {
                App.showAlert('添加成功');
                App.hideModal('addWordModal');
                this.load();
                
                // 重置表单
                const form = document.querySelector('#addWordModal form');
                if (form) {
                    form.reset();
                    // 确保删除消息选项默认选中
                    const deleteMessageCheckbox = form.querySelector('#newDeleteMessage');
                    if (deleteMessageCheckbox) {
                        deleteMessageCheckbox.checked = true;
                    }
                    // 确保匹配方式默认为精确匹配
                    const matchTypeSelect = form.querySelector('#newMatchType');
                    if (matchTypeSelect) {
                        matchTypeSelect.value = 'exact';
                    }
                }
            }
        } catch (error) {
            console.error('Error adding banned word:', error);
            App.showAlert('添加失败：' + error.message, 'error');
        }
    },

    async delete(id) {
        if (!App.confirm('确定要删除这个违禁词吗？')) {
            return;
        }

        const result = await App.request('api/banned_words.php', 'POST', {
            action: 'delete',
            id: id
        });

        if (result && result.success) {
            App.showAlert('删除成功');
            this.load();
        }
    },

    getActionText(action) {
        const actions = {
            'delete': '删除消息',
            'warn': '警告',
            'kick': '踢出',
            'ban': '封禁'
        };
        return actions[action] || action;
    },

    getActionColor(action) {
        const colors = {
            'delete': 'warning',
            'warn': 'primary',
            'kick': 'danger',
            'ban': 'danger'
        };
        return colors[action] || 'primary';
    }
};

// 自动广告管理
const AutoAds = {
    async load() {
        const result = await App.request('api/auto_ads.php?action=list');
        if (result && result.success) {
            this.render(result.data);
        }
    },

    render(ads) {
        const tbody = document.getElementById('autoAdsTable');
        if (!tbody) return;

        // 只显示不属于模板的独立广告
        const independentAds = ads.filter(ad => !ad.template_id);

        if (independentAds.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">暂无自动广告</td></tr>';
            return;
        }

        tbody.innerHTML = independentAds.map(ad => {
            const buttons = ad.buttons ? JSON.parse(ad.buttons) : [];
            const buttonCount = Array.isArray(buttons) ? buttons.length : 0;
            const categoryBadge = ad.category_name 
                ? `<span class="badge" style="background: ${ad.category_color || '#95a5a6'}; color: #fff; font-size: 10px; margin-left: 5px;">${ad.category_name}</span>` 
                : '';
            
            return `
            <tr>
                <td>${ad.id}</td>
                <td>${ad.group_title}${categoryBadge}</td>
                <td>${ad.message.substring(0, 50)}${ad.message.length > 50 ? '...' : ''}</td>
                <td>${ad.image_url ? '<span class="badge badge-success">✓</span>' : '-'}</td>
                <td>${buttonCount > 0 ? '<span class="badge badge-info">' + buttonCount + '</span>' : '-'}</td>
                <td>${ad.interval_minutes} 分钟</td>
                <td><span class="badge badge-${ad.is_active ? 'success' : 'danger'}">${ad.is_active ? '启用' : '禁用'}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="editAd(${ad.id})">编辑</button>
                    <button class="btn btn-sm btn-danger" onclick="AutoAds.delete(${ad.id})">删除</button>
                    <button class="btn btn-sm btn-${ad.is_active ? 'warning' : 'success'}" onclick="AutoAds.toggle(${ad.id}, ${ad.is_active})">${ad.is_active ? '禁用' : '启用'}</button>
                </td>
            </tr>
        `}).join('');
    },

    async add() {
        // 获取选中的群组ID
        let selectedGroupIds = [];
        const allCheckbox = document.getElementById('adGroupAll');
        if (allCheckbox && allCheckbox.checked) {
            selectedGroupIds = ['0'];
        } else {
            const checkboxes = document.querySelectorAll('.ad-group-checkbox:checked');
            checkboxes.forEach(cb => selectedGroupIds.push(cb.value));
        }
        
        const message = document.getElementById('adMessage').value;
        const keywords = document.getElementById('adKeywords')?.value || '';
        const keywordsPerSend = document.getElementById('adKeywordsPerSend')?.value || 3;
        const imageFile = document.getElementById('adImage')?.files[0];
        const interval = document.getElementById('adInterval').value;
        const deleteAfter = document.getElementById('adDeleteAfter')?.value || 0;
        const buttons = typeof getAdButtons === 'function' ? getAdButtons() : [];

        if (selectedGroupIds.length === 0 || !message || !interval) {
            App.showAlert('请选择群组并填写所有字段', 'error');
            return;
        }

        // Use FormData for file upload
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('group_ids', JSON.stringify(selectedGroupIds)); // 发送多个群组ID
        formData.append('message', message);
        formData.append('keywords', keywords);
        formData.append('keywords_per_send', keywordsPerSend);
        formData.append('interval_minutes', interval);
        formData.append('delete_after_seconds', deleteAfter);
        if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
        if (imageFile) formData.append('image', imageFile);

        try {
            const response = await fetch('api/auto_ads.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result && result.success) {
                App.showAlert(result.message || '添加成功');
                App.hideModal('addAdModal');
                // 清空表单
                document.getElementById('adMessage').value = '';
                const keywordsInput = document.getElementById('adKeywords');
                if (keywordsInput) keywordsInput.value = '';
                const keywordsPerSendInput = document.getElementById('adKeywordsPerSend');
                if (keywordsPerSendInput) keywordsPerSendInput.value = '3';
                const keywordsCountSpan = document.getElementById('adKeywordsCount');
                if (keywordsCountSpan) keywordsCountSpan.innerHTML = '已导入 0 个关键词';
                document.getElementById('adImage').value = '';
                document.getElementById('adInterval').value = '60';
                document.getElementById('adDeleteAfter').value = '0';
                document.getElementById('adButtonsContainer').innerHTML = '';
                document.getElementById('adImagePreview').innerHTML = '';
                // 清空复选框选择
                document.querySelectorAll('.ad-group-checkbox').forEach(cb => cb.checked = false);
                const allCheckbox = document.getElementById('adGroupAll');
                if (allCheckbox) allCheckbox.checked = false;
                this.load();
            } else {
                App.showAlert(result.message || '添加失败', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            App.showAlert('添加失败：' + error.message, 'error');
        }
    },

    async delete(id) {
        if (!App.confirm('确定要删除这个广告吗？')) {
            return;
        }

        const result = await App.request('api/auto_ads.php', 'POST', {
            action: 'delete',
            id: id
        });

        if (result && result.success) {
            App.showAlert('删除成功');
            this.load();
        }
    },

    async toggle(id, currentStatus) {
        const result = await App.request('api/auto_ads.php', 'POST', {
            action: 'toggle',
            id: id,
            is_active: currentStatus ? 0 : 1
        });

        if (result && result.success) {
            App.showAlert('状态更新成功');
            this.load();
        }
    }
};

// 群组管理
const Groups = {
    async load() {
        const result = await App.request('api/groups.php?action=list');
        if (result && result.success) {
            this.render(result.data);
        }
    },

    render(groups) {
        const tbody = document.getElementById('groupsTable');
        if (!tbody) return;

        if (groups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">暂无群组</td></tr>';
            return;
        }

        // 只显示活跃且未删除的群组（使用宽松比较，因为数据库可能返回字符串）
        const activeGroups = groups.filter(group => group.is_active == 1 && group.is_deleted == 0);
        console.log('Filtered groups:', activeGroups, 'Total groups:', groups.length);
        
        // 来源标识映射
        const sourceLabels = {
            'bot': '<span class="badge badge-primary">🤖 机器人</span>',
            'user_account': '<span class="badge badge-info">👤 真人账号</span>',
            'both': '<span class="badge badge-success">🤖👤 两者都在</span>'
        };
        
        // 群组类型映射
        const typeLabels = {
            'channel': '<span class="badge badge-warning">📢 频道</span>',
            'supergroup': '<span class="badge badge-info">👥 超级群</span>',
            'group': '<span class="badge badge-secondary">👥 普通群</span>'
        };
        
        tbody.innerHTML = activeGroups.map(group => {
            const source = group.source || 'bot';
            const type = group.type || 'group';
            const categoryBadge = group.category_name 
                ? `<span class="badge" style="background: ${group.category_color || '#95a5a6'}; color: #fff; font-size: 10px; margin-left: 5px;">${group.category_name}</span>` 
                : '<span class="badge" style="background: #95a5a6; color: #fff; font-size: 10px; margin-left: 5px;">未分类</span>';
            return `
            <tr>
                <td>${group.id}</td>
                <td>${group.title}${categoryBadge}</td>
                <td>${typeLabels[type] || typeLabels['group']}</td>
                <td>${group.chat_id}</td>
                <td>${group.member_count || 0}</td>
                <td>${sourceLabels[source] || sourceLabels['bot']}</td>
                <td><span class="badge badge-success">活跃</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="Groups.viewMembers(${group.id})">成员</button>
                    <button class="btn btn-sm btn-danger" onclick="Groups.leave(${group.id})">退出</button>
                </td>
            </tr>
        `}).join('');
    },

    async leave(id) {
        if (!App.confirm('确定要退出这个群组吗？')) {
            return;
        }

        const result = await App.request('api/groups.php', 'POST', {
            action: 'leave',
            id: id
        });

        if (result && result.success) {
            App.showAlert('已退出群组');
            this.load();
        }
    },

    viewMembers(groupId) {
        window.location.href = `members.php?group_id=${groupId}`;
    }
};

// 菜单按钮管理
const MenuButtons = {
    async load() {
        try {
            const result = await App.request('api/menu_buttons.php?action=list');
            
            if (result && result.success) {
                this.render(result.data);
            }
        } catch (error) {
            console.error('Error loading menu buttons:', error);
            App.showAlert('加载失败：' + error.message, 'error');
        }
    },

    render(buttons) {
        const container = document.getElementById('buttonsList');
        if (!container) return;

        if (!buttons || buttons.length === 0) {
            container.innerHTML = '<div class="alert alert-info">暂无菜单按钮</div>';
            return;
        }

        container.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>按钮文字</th>
                        <th>跳转链接</th>
                        <th>排序顺序</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${buttons.map(button => `
                        <tr>
                            <td>${button.id}</td>
                            <td>${button.button_text}</td>
                            <td><a href="${button.button_url}" target="_blank">${button.button_url}</a></td>
                            <td>${button.sort_order}</td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="MenuButtons.delete(${button.id})">删除</button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    },

    async add() {
        try {
            const buttonText = document.getElementById('buttonText')?.value;
            const buttonUrl = document.getElementById('buttonUrl')?.value;
            const sortOrder = document.getElementById('sortOrder')?.value;

            if (!buttonText || !buttonUrl) {
                App.showAlert('请填写完整信息', 'error');
                return;
            }

            const result = await App.request('api/menu_buttons.php', 'POST', {
                action: 'add',
                button_text: buttonText,
                button_url: buttonUrl,
                sort_order: sortOrder
            });

            if (result && result.success) {
                App.showAlert('添加成功');
                App.hideModal('addButtonModal');
                this.load();
                
                // 重置表单
                const form = document.getElementById('addButtonForm');
                if (form) {
                    form.reset();
                }
            }
        } catch (error) {
            console.error('Error adding menu button:', error);
            App.showAlert('添加失败：' + error.message, 'error');
        }
    },

    async delete(id) {
        if (!confirm('确定要删除这个按钮吗？')) {
            return;
        }

        try {
            const result = await App.request('api/menu_buttons.php', 'POST', {
                action: 'delete',
                id: id
            });

            if (result && result.success) {
                App.showAlert('删除成功');
                this.load();
            }
        } catch (error) {
            console.error('Error deleting menu button:', error);
            App.showAlert('删除失败：' + error.message, 'error');
        }
    }
};

// 页面加载完成
document.addEventListener('DOMContentLoaded', () => {
    // 根据当前页面加载相应数据
    if (typeof BannedWords !== 'undefined' && document.getElementById('bannedWordsTable')) {
        BannedWords.load();
    }
    
    if (typeof AutoAds !== 'undefined' && document.getElementById('autoAdsTable')) {
        AutoAds.load();
    }
    
    if (typeof Groups !== 'undefined' && document.getElementById('groupsTable')) {
        Groups.load();
    }

    // 加载菜单按钮列表
    if (typeof MenuButtons !== 'undefined' && document.getElementById('buttonsList')) {
        MenuButtons.load();
    }

    // 模态框点击外部关闭
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
});