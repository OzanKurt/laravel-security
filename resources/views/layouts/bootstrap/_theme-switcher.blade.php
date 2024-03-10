<button class="nav-link d-flex align-items-center tw-space-x-2"
        id="bd-theme" type="button" aria-expanded="false" data-bs-toggle="dropdown"
        data-bs-display="static" aria-label="Toggle theme (auto)">
    <div>
        <span class="theme-icon-active my-1">
            <i class="far fa-fw fa-moon"></i>
        </span>
        <span class="d-md-none ms-2" id="bd-theme-text">Toggle theme</span>
        <span>
            <i class="far fa-fw fa-caret-down"></i>
        </span>
    </div>
</button>
<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="bd-theme-text">
    <li>
        <button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="light" aria-pressed="false">
            <span class=" me-2 opacity-50 theme-icon">
                <i class="far fa-fw fa-sun"></i>
            </span>
            @lang("Light")
            <i class="far fa-fw fa-check ms-auto d-none"></i>
        </button>
    </li>
    <li>
        <button type="button" class="dropdown-item d-flex align-items-center active" data-bs-theme-value="dark" aria-pressed="true">
            <span class=" me-2 opacity-50 theme-icon">
                <i class="far fa-fw fa-moon"></i>
            </span>
            @lang("Dark")
            <i class="far fa-fw fa-check ms-auto d-none"></i>
        </button>
    </li>
    <li>
        <button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="auto" aria-pressed="false">
            <span class=" me-2 opacity-50 theme-icon">
                <i class="far fa-fw fa-adjust"></i>
            </span>
            @lang("Auto")
            <i class="far fa-fw fa-check ms-auto d-none"></i>
        </button>
    </li>
</ul>

@push('scripts')
    <!-- Bootstrap Theme Switcher -->
    <script>
        /**
         * Color mode toggler for Bootstrap's docs (https://getbootstrap.com/)
         * Copyright 2011-2024 The Bootstrap Authors
         * Licensed under the Creative Commons Attribution 3.0 Unported License.
         */

        (() => {
            'use strict'

            const getStoredTheme = () => localStorage.getItem('security-theme')
            const setStoredTheme = theme => localStorage.setItem('security-theme', theme)

            const getPreferredTheme = () => {
                const storedTheme = getStoredTheme()
                if (storedTheme) {
                    return storedTheme
                }

                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            }

            const setTheme = theme => {
                if (theme === 'auto') {
                    document.documentElement.setAttribute('data-bs-theme', (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'))
                } else {
                    document.documentElement.setAttribute('data-bs-theme', theme)
                }
            }

            setTheme(getPreferredTheme())

            const showActiveTheme = (theme, focus = false) => {
                const themeSwitcher = document.querySelector('#bd-theme')

                if (!themeSwitcher) {
                    return
                }

                const themeSwitcherText = document.querySelector('#bd-theme-text')
                const activeThemeIcon = document.querySelector('.theme-icon-active i.far')
                const btnToActive = document.querySelector(`[data-bs-theme-value="${theme}"]`)
                const iconOfActiveBtn = btnToActive.querySelector('i.far')

                document.querySelectorAll('[data-bs-theme-value]').forEach(element => {
                    element.classList.remove('active')
                    element.setAttribute('aria-pressed', 'false')
                })

                btnToActive.classList.add('active')
                btnToActive.setAttribute('aria-pressed', 'true')
                activeThemeIcon.classList.remove('fa-moon', 'fa-sun', 'fa-adjust')
                activeThemeIcon.classList.add(...iconOfActiveBtn.classList)
                const themeSwitcherLabel = `${themeSwitcherText.textContent} (${btnToActive.dataset.bsThemeValue})`
                themeSwitcher.setAttribute('aria-label', themeSwitcherLabel)

                if (focus) {
                    themeSwitcher.focus()
                }
            }

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                const storedTheme = getStoredTheme()
                if (storedTheme !== 'light' && storedTheme !== 'dark') {
                    setTheme(getPreferredTheme())
                }
            })

            window.addEventListener('DOMContentLoaded', () => {
                showActiveTheme(getPreferredTheme())

                document.querySelectorAll('[data-bs-theme-value]')
                    .forEach(toggle => {
                        toggle.addEventListener('click', () => {
                            const theme = toggle.getAttribute('data-bs-theme-value')
                            setStoredTheme(theme)
                            setTheme(theme)
                            showActiveTheme(theme, true)
                        })
                    })
            })
        })()
    </script>
@endpush
