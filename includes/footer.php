</div>
    <footer class="app-footer">
        <div class="container-fluid">
            <div class="d-flex justify-content-start align-items-center">
                <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Aplikasi Manajemen Showroom'); ?></span>
            </div>
        </div>
    </footer>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Choices.js JS -->
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    
    <!-- Custom JS -->
    <script src="<?php echo ASSETS_PATH; ?>js/main.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/main.js') ?: time(); ?>"></script>
    
    <!-- Dark/Light Mode Toggle Script -->
    <script>
    (function() {
        var html = document.documentElement;
        var toggleBtn = document.getElementById('themeToggleBtn');
        var themeIcon = document.getElementById('themeIcon');
        var themeLabel = document.getElementById('themeLabel');
        
        function getCurrentTheme() {
            var saved = localStorage.getItem('theme');
            if (saved) return saved;
            // Respect OS preference
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        
        function applyTheme(theme) {
            if (theme === 'dark') {
                html.setAttribute('data-theme', 'dark');
                if (themeIcon) {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                }
                if (themeLabel) themeLabel.textContent = 'Light';
                if (toggleBtn) toggleBtn.setAttribute('aria-label', 'Aktifkan mode terang');
            } else {
                html.removeAttribute('data-theme');
                if (themeIcon) {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
                if (themeLabel) themeLabel.textContent = 'Dark';
                if (toggleBtn) toggleBtn.setAttribute('aria-label', 'Aktifkan mode gelap');
            }
        }
        
        // Apply theme on load (icon/label sync — theme already applied in head)
        applyTheme(getCurrentTheme());
        
        // Toggle on click
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                var current = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                var newTheme = current === 'dark' ? 'light' : 'dark';
                
                // Add a brief animation to the icon
                if (themeIcon) {
                    themeIcon.style.transform = 'rotate(360deg) scale(0)';
                    setTimeout(function() {
                        applyTheme(newTheme);
                        themeIcon.style.transform = 'rotate(0deg) scale(1)';
                    }, 200);
                } else {
                    applyTheme(newTheme);
                }
                
                localStorage.setItem('theme', newTheme);
            });
        }
        
        // Listen for OS preference changes
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
                if (!localStorage.getItem('theme')) {
                    applyTheme(e.matches ? 'dark' : 'light');
                }
            });
        } catch(e) {}
    })();
    </script>
    
    <script>
    (function() {
        var searchInput = document.getElementById('globalSearchInput');
        var dropdown = document.getElementById('searchResultsDropdown');
        if (!searchInput || !dropdown) return;

        var debounceTimer = null;
        var baseUrl = '<?php echo defined("BASE_URL") ? BASE_URL : "/showroom/"; ?>';
        var currentXHR = null;

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var q = this.value.trim();
            if (q.length < 2) {
                dropdown.classList.remove('active');
                dropdown.innerHTML = '';
                return;
            }
            // Show loading state
            dropdown.innerHTML = '<div class="search-no-result"><i class="fas fa-spinner fa-spin me-2"></i>Mencari...</div>';
            dropdown.classList.add('active');

            debounceTimer = setTimeout(function() {
                // Cancel previous request if still in flight
                if (currentXHR && currentXHR.abort) {
                    currentXHR.abort();
                }

                currentXHR = fetch(baseUrl + 'search.php?q=' + encodeURIComponent(q))
                    .then(function(r) {
                        if (r.status === 401) {
                            // Session expired — redirect to login
                            window.location.href = baseUrl + 'auth/login.php?reason=timeout';
                            return null;
                        }
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status);
                        }
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data) return; // redirected
                        if (!data.results || data.results.length === 0) {
                            dropdown.innerHTML = '<div class="search-no-result"><i class="fas fa-search me-2"></i>Tidak ada hasil untuk "<strong>' + q.replace(/</g,'&lt;') + '</strong>"</div>';
                            dropdown.classList.add('active');
                            return;
                        }
                        var html = '';
                        var types = {};
                        data.results.forEach(function(r) {
                            if (!types[r.type]) types[r.type] = [];
                            types[r.type].push(r);
                        });
                        var labels = { vehicle: 'Kendaraan', customer: 'Pelanggan', transaction: 'Transaksi' };
                        for (var type in types) {
                            html += '<div class="search-category-label">' + (labels[type] || type) + '</div>';
                            types[type].forEach(function(r) {
                                html += '<a href="' + r.url + '" class="search-result-item">';
                                html += '<div class="search-result-icon ' + r.type + '"><i class="' + r.icon + '"></i></div>';
                                html += '<div><div class="search-result-title">' + r.title + '</div>';
                                html += '<div class="search-result-sub">' + r.subtitle + '</div></div>';
                                html += '</a>';
                            });
                        }
                        dropdown.innerHTML = html;
                        dropdown.classList.add('active');
                    })
                    .catch(function(err) {
                        // Don't hide dropdown on abort, only on real errors
                        if (err.name !== 'AbortError') {
                            dropdown.innerHTML = '<div class="search-no-result"><i class="fas fa-exclamation-triangle me-2"></i>Gagal memuat hasil pencarian</div>';
                            dropdown.classList.add('active');
                        }
                    });
            }, 300);
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('active');
            }
        });

        // Close on Escape
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.classList.remove('active');
                searchInput.blur();
            }
        });
    })();
    </script>
    
    <script>
        // Prevent browser from caching authenticated pages and going back after logout
        (function() {
            window.history.pushState(null, "", window.location.href);
            window.onpopstate = function() {
                window.history.pushState(null, "", window.location.href);
            };
        })();
        
        // Define toggleSidebar function immediately (available globally)
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (!sidebar || !mainContent) { return; }
            
            // Mobile: toggle show/hide
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('show');
            } 
            // Desktop: toggle collapsed/expanded
            else {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
                
                // Save state to localStorage
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
            }
        };
        
        // Initialize sidebar on page load
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (sidebar && mainContent) {
                // Restore collapsed state on desktop
                if (window.innerWidth > 768) {
                    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                    if (isCollapsed) {
                        sidebar.classList.add('collapsed');
                        mainContent.classList.add('sidebar-collapsed');
                    }
                }
            }

            // Remove pre-collapse helper class after initialization
            try { document.documentElement.classList.remove('sidebar-collapsed-init'); } catch (e) {}
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (!sidebar || !mainContent) return;
            
            if (window.innerWidth > 768) {
                // Desktop: remove mobile classes, restore collapsed state
                sidebar.classList.remove('show');
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('sidebar-collapsed');
                }
            } else {
                // Mobile: remove desktop classes
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
            }
        });
        
        // Auto-hide alerts after a few seconds (3s) - exclude alerts with no-auto-hide class
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert:not(.no-auto-hide)');
            alerts.forEach(function(alert) {
                try {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                } catch (e) {
                    // ignore
                }
            });
        }, 3000);

        // Sidebar submenu toggle (Data Master) - works in collapsed/minimized mode too
        document.addEventListener('click', function(e) {
            const trigger = e.target.closest && e.target.closest('[data-submenu-target]');
            if (!trigger) return;

            const id = trigger.getAttribute('data-submenu-target');
            if (!id) return;

            const el = document.getElementById(id);
            if (!el) return;

            e.preventDefault();
            el.classList.toggle('show');
            trigger.setAttribute('aria-expanded', el.classList.contains('show') ? 'true' : 'false');
        });
        
        // Confirm delete actions
        function confirmDelete(message) {
            return confirm(message || 'Apakah Anda yakin ingin menghapus data ini?');
        }
        
        // Format currency input
        function formatCurrency(input) {
            let value = input.value.replace(/[^\d]/g, '');
            input.value = new Intl.NumberFormat('id-ID').format(value);
        }
        
        // Parse currency value
        function parseCurrency(value) {
            return parseInt(value.replace(/[^\d]/g, '')) || 0;
        }
    </script>
    <script>
        // Initialize dropdowns with Popper boundary to avoid clipping inside overflowed containers
        document.addEventListener('DOMContentLoaded', function () {
            var dropdownElements = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            dropdownElements.forEach(function (el) {
                try {
                    new bootstrap.Dropdown(el, { popperConfig: { boundariesElement: 'viewport' } });
                } catch (e) {
                    // ignore if initialization fails
                }
            });
        });
    </script>
</body>
</html>
