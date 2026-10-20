        </div>
    </main>
    <?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
    <footer class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 mt-auto relative z-10 shadow-lg" style="margin-left:0 ; width: 100%;">
        <style>
            @media (min-width: 768px) {
                footer {
                    margin-right: 18rem !important; /* 288px = w-72 */
                    width: calc(100% - 18rem) !important;
                }
            }
        </style>
        <div class="max-w-4xl mx-auto px-2 py-6">
            <div class="flex flex-col md:flex-row items-center justify-between">
                <div class="flex items-center mb-4 md:mb-0">
                    <i class="fas fa-graduation-cap text-blue-600 dark:text-blue-400 text-xl ml-2"></i>
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(SITE_NAME); ?>
                    </span>
                </div>
                <div class="flex items-center space-x-6 space-x-reverse">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        جميع الحقوق محفوظة
                    </span>
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <span class="text-xs text-gray-500 dark:text-gray-400">تم التطوير بـ</span>
                        <i class="fas fa-heart text-red-500 text-xs"></i>
                        <span class="text-xs text-gray-500 dark:text-gray-400">eng\abdullahreda</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <!-- زر الانتقال للأعلى -->
    <button id="scrollToTop" onclick="scrollToTop()" class="fixed bottom-6 left-6 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 dark:from-blue-700 dark:to-blue-800 dark:hover:from-blue-600 dark:hover:to-blue-700 text-white p-3 rounded-full shadow-xl hover:shadow-2xl transition-all duration-300 z-50 hidden group">
        <i class="fas fa-arrow-up group-hover:animate-bounce-subtle"></i>
    </button>

    <script>
        function searchWidget() {
            return {
                query: '',
                open: false,
                isLoading: false,
                results: [],
                debounceTimer: null,
                onInput() {
                    this.open = true;
                    clearTimeout(this.debounceTimer);
                    if (this.query.trim().length < 2) { this.results = []; return; }
                    this.debounceTimer = setTimeout(() => this.fetchResults(false), 300);
                },
                async fetchResults(isExplicit) {
                    this.isLoading = true;
                    try {
                        const resp = await fetch('<?php echo rtrim(SITE_URL,'/'); ?>/api/search.php?q=' + encodeURIComponent(this.query.trim()));
                        if (!resp.ok) throw new Error('Network');
                        const data = await resp.json();
                        this.results = Array.isArray(data) ? data : (data.results || []);
                        if (isExplicit && this.results.length === 1) {
                            window.location = this.results[0].url;
                        }
                    } catch (e) {
                        this.results = [];
                    } finally {
                        this.isLoading = false;
                    }
                }
            }
        }

        // دالة الانتقال إلى أعلى الصفحة
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // إظهار/إخفاء زر الانتقال للأعلى
        window.addEventListener('scroll', function() {
            const scrollButton = document.getElementById('scrollToTop');
            if (scrollButton) {
                if (window.pageYOffset > 300) {
                    scrollButton.style.display = 'block';
                } else {
                    scrollButton.style.display = 'none';
                }
            }
        });
    </script>
    <?php
    // Render flash for toast (if any) and then clear it so it doesn't persist
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flash = $_SESSION['flash'] ?? null;
    if ($flash) {
        // make a JSON-safe copy for embedding in JS
        $flash_json = json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        // clear flash so it won't reappear
        unset($_SESSION['flash']);
    } else {
        $flash_json = 'null';
    }
    ?>

    <!-- Toast container (RTL-friendly) -->
    <div id="toast-container" class="fixed top-6 left-6 z-50 flex flex-col items-start space-y-3 pointer-events-none"></div>

    <style>
        /* toast enter/exit animations */
        .toast-enter {
            transform: translateX(-12px) translateY(6px) scale(.98);
            opacity: 0;
        }
        .toast-enter-active {
            transform: translateX(0) translateY(0) scale(1);
            opacity: 1;
            transition: transform 320ms cubic-bezier(.2,.8,.2,1), opacity 320ms ease;
        }
        .toast-exit {
            transform: translateX(0);
            opacity: 1;
        }
        .toast-exit-active {
            transform: translateX(-8px);
            opacity: 0;
            transition: transform 220ms ease, opacity 220ms ease;
        }
    </style>

    <script>
        (function(){
            const flash = <?php echo $flash_json; ?>;
            if (!flash) return;

            const container = document.getElementById('toast-container');
            function createToast(opts) {
                const id = 'toast_' + Date.now();
                const type = opts.type || 'info';
                const title = opts.title || '';
                const message = opts.message || '';
                const autoHide = typeof opts.autoHide === 'undefined' ? true : !!opts.autoHide;

                const colorMap = {
                    success: 'bg-green-50 border-green-200 text-green-800',
                    error: 'bg-red-50 border-red-200 text-red-800',
                    warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
                    info: 'bg-blue-50 border-blue-200 text-blue-800'
                };
                const iconMap = {
                    success: '<i class="fas fa-check-circle"></i>',
                    error: '<i class="fas fa-times-circle"></i>',
                    warning: '<i class="fas fa-exclamation-triangle"></i>',
                    info: '<i class="fas fa-info-circle"></i>'
                };

                const wrap = document.createElement('div');
                wrap.className = 'pointer-events-auto w-96 max-w-full ' + ' ' + (colorMap[type] || colorMap.info) + ' border rounded-lg shadow-lg overflow-hidden transform-gpu';
                wrap.style.direction = 'rtl';
                wrap.id = id;

                // content
                wrap.innerHTML = `
                    <div class="flex items-start p-3 gap-3">
                        <div class="text-2xl w-9 h-9 flex items-center justify-center rounded-full bg-white/30 text-current">
                            ${iconMap[type] || iconMap.info}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm">${escapeHtml(title)}</div>
                            <div class="text-sm mt-1 opacity-90">${escapeHtml(message)}</div>
                        </div>
                        <button aria-label="إغلاق" class="ml-3 text-current opacity-80 hover:opacity-100 bg-white/10 rounded p-1" data-close>
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;

                // initial enter state
                wrap.classList.add('toast-enter');
                container.insertBefore(wrap, container.firstChild);

                // force reflow then add active
                requestAnimationFrame(() => {
                    wrap.classList.add('toast-enter-active');
                    wrap.classList.remove('toast-enter');
                });

                // close handler
                wrap.querySelectorAll('[data-close]').forEach(function(btn){
                    btn.addEventListener('click', function(){ removeToast(wrap); });
                });

                let hideTimer = null;
                if (autoHide) {
                    hideTimer = setTimeout(() => removeToast(wrap), 6000);
                    // pause on hover
                    wrap.addEventListener('mouseenter', () => clearTimeout(hideTimer));
                    wrap.addEventListener('mouseleave', () => hideTimer = setTimeout(() => removeToast(wrap), 3000));
                }

                function removeToast(el) {
                    if (!el) return;
                    el.classList.add('toast-exit-active');
                    setTimeout(() => { try { el.remove(); } catch(e){} }, 260);
                }
            }

            function escapeHtml(str) {
                if (typeof str !== 'string') return '';
                return str.replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
            }

            // show the flash
            createToast({ type: flash.type || 'info', title: flash.title || '', message: flash.message || '', autoHide: (typeof flash.autoHide === 'undefined') ? (flash.type !== 'error') : !!flash.autoHide });
        })();
    </script>
</body>
</html>


