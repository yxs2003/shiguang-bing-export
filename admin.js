var currentPage = 1; // 全局变量记录当前页

jQuery(document).ready(function($) {

    // Toast
    window.showToast = function(msg, type = 'success') {
        $('.sg-toast').remove();
        let icon = type === 'success' ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-warning"></span>';
        if(type === 'loading') icon = '<span class="dashicons dashicons-update" style="animation:spin 1s infinite linear"></span>';
        const toast = $(`<div class="sg-toast ${type}">${icon} ${msg}</div>`);
        $('body').append(toast);
        setTimeout(() => toast.addClass('show'), 10);
        if(type !== 'loading') {
            setTimeout(() => { toast.removeClass('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        }
    };

    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        let textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        document.body.appendChild(textArea);
        textArea.select();
        return new Promise((res) => { document.execCommand('copy') ? res() : null; textArea.remove(); });
    }

    // Load Data (增加 page 参数)
    window.loadData = function(page = 1) {
        if (!sgbingVars || !sgbingVars.ajaxUrl) return;
        
        // 只有第一页或者首次加载显示加载中
        if(page === 1) $('#log-tbody').html('<tr><td colspan="6" style="text-align:center; padding:20px; color:#666;">正在同步 Bing 数据...</td></tr>');
        
        $.post(sgbingVars.ajaxUrl, { 
            action: 'sgbing_get_data', 
            nonce: sgbingVars.nonce,
            page: page,
            _t: new Date().getTime() 
        }, function(res) {
            if(!res.success) {
                $('#log-tbody').html('<tr><td colspan="6" style="text-align:center; color:red;">数据加载失败</td></tr>');
                return;
            }
            const data = res.data;
            currentPage = page; // 更新当前页

            // 渲染日志
            let html = '';
            if(data.logs && data.logs.length > 0) {
                data.logs.forEach(l => {
                    let statusClass = l.status === 'Success' ? 'success' : 'failed';
                    let method = l.method ? l.method : 'API';
                    let methodClass = method.toLowerCase().includes('index') ? 'indexnow' : 'api';
                    html += `<tr>
                        <td style="color:#605e5c;">${l.time.substring(5, 16)}</td>
                        <td><span class="badge ${methodClass}">${method}</span></td>
                        <td><div class="url-wrapper"><a href="${l.url}" target="_blank">${l.url}</a></div></td>
                        <td><span class="badge ${statusClass}">${l.status}</span></td>
                        <td class="msg-cell" title="${l.msg}">${l.msg.length > 25 ? l.msg.substring(0, 25) + '...' : l.msg}</td>
                        <td><button class="sg-btn sm outline" onclick="submitManual('${l.url}', 'api')">重试</button></td>
                    </tr>`;
                });
            } else { html = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">暂无数据</td></tr>'; }
            $('#log-tbody').html(html);

            // 渲染分页
            if(data.pagination) {
                renderPagination(data.pagination);
            }

            // Stats
            $('#stat-24h-total').text(data.stats.total || 0);
            $('#stat-24h-success').text(data.stats.success || 0);
            $('#stat-24h-failed').text(data.stats.failed || 0);

            // Quota
            if(data.quota) {
                if(data.quota.daily === -1) {
                     $('#quota-circle-path').attr('stroke-dasharray', `0, 100`).css('stroke', '#ccc');
                     $('#quota-text').text('Err');
                     $('#quota-limit-text').text('连接失败');
                } else {
                    let remaining = parseInt(data.quota.daily) || 0;
                    let limit = parseInt(data.quota.limit) || 100;
                    let percent = Math.round((remaining / limit) * 100);
                    if(percent > 100) percent = 100;
                    $('#quota-circle-path').attr('stroke-dasharray', `${percent}, 100`);
                    $('#quota-text').text(percent + '%');
                    $('#quota-limit-text').text(limit);
                    let strokeColor = (remaining < (limit * 0.2)) ? '#d13438' : '#881798';
                    $('#quota-circle-path').css('stroke', strokeColor);
                    if(remaining < 5) $('#quota-warning').slideDown(); else $('#quota-warning').hide();
                }
            }
        });
    };

    // 渲染分页 HTML
    function renderPagination(pg) {
        const total = parseInt(pg.total_pages);
        const current = parseInt(pg.current_page);
        let html = '';
        
        if(total > 1) {
            html += `<button class="sg-btn sm outline page-btn" ${current===1?'disabled':''} data-page="${current-1}">上一页</button>`;
            html += `<span class="page-info">第 ${current} / ${total} 页</span>`;
            html += `<button class="sg-btn sm outline page-btn" ${current===total?'disabled':''} data-page="${current+1}">下一页</button>`;
        }
        $('#sg-pagination').html(html);
    }

    // 分页点击事件
    $(document).on('click', '.page-btn', function() {
        const page = $(this).data('page');
        loadData(page);
    });

    // Manual Submit
    window.submitManual = function(urlOverride, methodOverride) {
        let urls = urlOverride ? urlOverride : $('#manual-urls').val();
        if(!urls) return showToast('请输入链接', 'error');

        let channel = methodOverride;
        if(!channel) channel = $('input[name="submit_channel"]:checked').val();

        const btn = urlOverride ? null : $('#tab-bulk .sg-btn.primary.full');
        let orgText = '';
        if(btn) { orgText = btn.text(); btn.text('处理中...').prop('disabled', true); }
        else { showToast('正在后台重试...', 'loading'); }

        $.post(sgbingVars.ajaxUrl, {
            action: 'sgbing_manual_submit',
            nonce: sgbingVars.nonce,
            urls: urls,
            channel: channel
        }, function(res) {
            if(btn) btn.text(orgText).prop('disabled', false);
            if(res.success) {
                showToast(res.data === 'OK' ? '提交成功' : '已自动转为 IndexNow', 'success');
                if(!urlOverride) $('#manual-urls').val('');
                loadData(1); // 提交成功后回到第一页
            } else {
                showToast(res.data, 'error');
                loadData(currentPage);
            }
        });
    };

    window.genIndexNow = function() {
        if(!confirm('确定重新生成 IndexNow Key 吗？')) return;
        showToast('正在生成...', 'loading');
        $.post(sgbingVars.ajaxUrl, { action: 'sgbing_gen_indexnow', nonce: sgbingVars.nonce }, function(res) {
            if(res.success) { showToast('成功！刷新中...', 'success'); setTimeout(() => location.reload(), 1500); }
        });
    };

    window.resetKey = function() {
        if(confirm('确定删除密钥？')) {
            $.post(sgbingVars.ajaxUrl, { action:'sgbing_handle_key', type:'reset', nonce:sgbingVars.nonce }, function() { 
                showToast('已删除', 'success'); setTimeout(() => location.reload(), 1000);
            });
        }
    };
    
    window.saveSettings = function() {
        const btn = $('#tab-sitemap button.primary');
        const orgText = btn.text();
        btn.text('保存中...').prop('disabled', true);
        $.post(sgbingVars.ajaxUrl, {
            action: 'sgbing_save_settings',
            nonce: sgbingVars.nonce,
            enable: $('#sm_enable').is(':checked')
        }, function(res) {
            btn.text(orgText).prop('disabled', false);
            showToast('配置已保存', 'success');
        });
    };

    $(document).on('click',('.btn-copy-link'), function() {
        const url = $(this).data('url');
        copyToClipboard(url).then(() => showToast('已复制链接', 'success'));
    });

    window.toggleNav = function() { $('#sg-sidebar').toggleClass('open'); };
    $('#btn-save-key').click(function() {
        const k = $('#api-key-input').val().trim();
        if(!k) return showToast('请输入 API Key', 'error');
        const btn = $(this);
        const orgText = btn.text();
        btn.text('验证中...').prop('disabled', true);
        $.post(sgbingVars.ajaxUrl, { action:'sgbing_handle_key', type:'save', key:k, nonce:sgbingVars.nonce }, function(res) { 
            if(res.success) { showToast('连接成功！', 'success'); setTimeout(() => location.reload(), 1000); }
            else { showToast('验证失败: ' + (res.data || 'Key 无效'), 'error'); btn.text(orgText).prop('disabled', false); }
        });
    });

    $('#sm_enable').change(function() {
        if($(this).is(':checked')) $('#sm-options').css({opacity:1, pointerEvents:'auto'});
        else $('#sm-options').css({opacity:0.5, pointerEvents:'none'});
    });

    $(document).on('click', '.btn-copy', function() {
        const targetId = $(this).data('target');
        copyToClipboard($('#' + targetId).find('textarea').val()).then(() => showToast('已复制', 'success'));
    });

    $(document).on('click', '.btn-toggle', function() {
        const targetId = $(this).data('target');
        const contentBox = $('#' + targetId);
        const isHidden = contentBox.is(':hidden');
        $('.batch-content').slideUp(200);
        $('.btn-toggle').text('查看');
        if(isHidden) { contentBox.slideDown(200); $(this).text('收起'); }
    });

    $(document).on('click', '.btn-submit-batch', function() {
        const wrapper = $(this).closest('.sg-batch-wrapper');
        const urls = wrapper.find('.batch-textarea').val();
        const channel = $('input[name="submit_channel"]:checked').val();
        if(!confirm(`确认提交该组？`)) return;
        window.submitManual(urls, channel);
    });

    function initTabs() {
        let activeTab = localStorage.getItem('sgbing_active_tab') || 'dashboard';
        if($('#tab-' + activeTab).length === 0) activeTab = 'dashboard';
        $('.nav-item').removeClass('active');
        $('.sg-pane').removeClass('active');
        $(`.nav-item[data-tab="${activeTab}"]`).addClass('active');
        $('#tab-' + activeTab).addClass('active');
    }

    $(document).on('click', '.nav-item', function(e) {
        e.preventDefault();
        const target = $(this).data('tab');
        if(!target) return;
        $('.nav-item').removeClass('active');
        $(this).addClass('active');
        $('.sg-pane').removeClass('active');
        $('#tab-' + target).addClass('active');
        localStorage.setItem('sgbing_active_tab', target);
        if($(window).width() < 768) $('#sg-sidebar').removeClass('open');
    });

    if($('#api-key-input').length === 0) { initTabs(); loadData(); }
});