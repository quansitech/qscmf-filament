/**
 * RichEditor 自定义块「公众号文章」：在块的标题栏加一个「重新抓取」按钮。
 *
 * 由 WechatEditorPlugin::getTipTapJsExtensions() 注入（Filament 会动态 import 本文件，
 * 取 default 导出的扩展实例）。按钮点击后调用签名路由 /wechat-article/refetch 强制
 * 重新抓取该文章（覆盖 wechat_articles 快照、重新落地图片），再去掉旧缓存重新加载预览
 * iframe。
 */
export default function () {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const { Plugin } = window.FilamentRichEditor.tiptap.pmState

    const BLOCK_ID = 'wechat-embed'

    // 「保存中（正在抓取公众号文章）」浮层，避免同步抓取期间看起来像系统卡死
    const OVERLAY_ID = 'wechat-article-saving-overlay'

    const ensureStyles = () => {
        if (document.getElementById('wechat-article-style')) {
            return
        }

        const style = document.createElement('style')
        style.id = 'wechat-article-style'
        style.textContent =
            '@keyframes wechat-article-spin{to{transform:rotate(360deg)}}'
            + '.wechat-article-spin{animation:wechat-article-spin 1s linear infinite}'
        document.head.appendChild(style)
    }

    const ensureOverlay = () => {
        let overlay = document.getElementById(OVERLAY_ID)

        if (overlay) {
            return overlay
        }

        ensureStyles()

        overlay = document.createElement('div')
        overlay.id = OVERLAY_ID
        overlay.setAttribute('role', 'status')
        overlay.setAttribute('aria-live', 'polite')
        overlay.style.cssText =
            'position:fixed;top:16px;right:16px;z-index:9999;display:none;'
            + 'align-items:center;gap:10px;padding:12px 16px;border-radius:10px;'
            + 'background:#fffbeb;border:1px solid #f59e0b;color:#92400e;'
            + 'font:14px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;'
            + 'box-shadow:0 10px 15px -3px rgba(0,0,0,.1);max-width:420px;'
        overlay.innerHTML =
            '<svg class="wechat-article-spin" style="flex:none" xmlns="http://www.w3.org/2000/svg" '
            + 'viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="18" height="18">'
            + '<path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" /></svg>'
            + '<span>正在抓取公众号文章并保存，请稍候…（视图片数量可能需要十几秒）</span>'
        document.body.appendChild(overlay)

        return overlay
    }

    let overlayCount = 0

    let overlayTimeout = null

    const showSaveOverlay = () => {
        overlayCount++
        ensureOverlay().style.display = 'flex'

        // 兜底：即使 Livewire respond 回调丢失（如 navigate 打断提交），
        // 也不让浮层永远悬挂（保存+抓取的理论上界远小于 120s）
        if (overlayTimeout) clearTimeout(overlayTimeout)
        overlayTimeout = setTimeout(hideSaveOverlay, 120000)
    }

    const hideSaveOverlay = () => {
        overlayCount = Math.max(0, overlayCount - 1)

        if (overlayCount === 0) {
            const overlay = document.getElementById(OVERLAY_ID)

            if (overlay) {
                overlay.style.display = 'none'
            }
        }
    }

    const resetSaveOverlay = () => {
        overlayCount = 0

        if (overlayTimeout) {
            clearTimeout(overlayTimeout)
            overlayTimeout = null
        }

        const overlay = document.getElementById(OVERLAY_ID)

        if (overlay) {
            overlay.remove()
        }
    }

    // 保存（create/save）会同步抓取公众号文章，给用户明确的中间状态反馈；
    // 普通字段更新（updates）不触发浮层，避免打字时闪烁
    const registerSaveIndicator = () => {
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
            return
        }

        window.Livewire.hook('commit', ({ component, commit, respond }) => {
            const calls = Array.isArray(commit?.calls) ? commit.calls : []

            const isSave = calls.some((call) =>
                ['create', 'save', 'submit'].includes(call.method),
            )

            if (!isSave) {
                return
            }

            const hasBlock = !!component?.el?.querySelector?.(
                'div[data-type="customBlock"][data-id="' + BLOCK_ID + '"]',
            )

            if (!hasBlock) {
                return
            }

            showSaveOverlay()
            respond(() => hideSaveOverlay())
        })

        // Livewire navigate（wire:navigate SPA 跳转）会替换 DOM 并打断在途提交，
        // 重置计数并移除浮层节点，避免 overlayCount 卡正数导致新页面浮层永不隐藏
        document.addEventListener('livewire:navigated', resetSaveOverlay)
    }

    // Heroicons 20/solid arrow-path，尺寸/样式与 Filament 自定义块自带的删除图标一致
    const ARROW_PATH =
        'M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z'

    // 使用 Filament 的通知系统（右上角 toast）；极少数未加载时退回 alert
    const notify = (status, title, body) => {
        try {
            if (window.FilamentNotification) {
                let notification = new window.FilamentNotification().title(title)

                if (body) {
                    notification = notification.body(body)
                }

                notification = notification[status]()

                notification.send()

                return
            }
        } catch (error) {
            // 落到下面的兜底
        }

        window.alert(body ? title + '\n' + body : title)
    }

    const decorate = (view) => {
        view.dom
            .querySelectorAll('[data-type="customBlock"]')
            .forEach((block) => {
                if (block.getAttribute('data-id') !== BLOCK_ID) {
                    return
                }

                if (block.querySelector('[data-wechat-refetch]')) {
                    return
                }

                const header = block.querySelector(
                    '.fi-fo-rich-editor-custom-block-header',
                )
                const iframe = block.querySelector('iframe')

                if (!header || !iframe) {
                    return
                }

                const button = document.createElement('button')
                button.type = 'button'
                button.setAttribute('data-wechat-refetch', '1')
                button.className = 'fi-icon-btn'
                button.title = '重新抓取最新内容'
                button.setAttribute('aria-label', '重新抓取最新内容')
                button.innerHTML =
                    '<svg class="fi-icon fi-size-md" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="' +
                    ARROW_PATH +
                    '" clip-rule="evenodd" /></svg>'

                button.addEventListener('click', async (event) => {
                    event.preventDefault()
                    event.stopPropagation()

                    const embedUrl = new URL(iframe.src, document.baseURI)
                    const refetchUrl = new URL(embedUrl.toString())
                    refetchUrl.pathname = refetchUrl.pathname.replace(
                        /\/embed$/,
                        '/refetch',
                    )
                    refetchUrl.searchParams.set('_', Date.now().toString())

                    button.disabled = true
                    button.classList.add('opacity-50')
                    button.setAttribute('aria-busy', 'true')
                    button.title = '抓取中…'
                    ensureStyles()
                    button.querySelector('svg')?.classList.add('wechat-article-spin')

                    try {
                        // 重新抓取是写操作：POST + CSRF（Filament 布局带 csrf-token meta），
                        // 并要求后台登录（服务端校验），防止被外部滥用为抓取代理
                        const csrfToken =
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || ''
                        const response = await fetch(refetchUrl.toString(), {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            credentials: 'same-origin',
                        })
                        const data = await response.json().catch(() => ({}))

                        if (!response.ok || data.ok === false) {
                            if (response.status === 419) {
                                throw new Error('页面已过期，请刷新后台页面后重试')
                            }

                            throw new Error(
                                data.message ||
                                    '重新抓取失败（HTTP ' + response.status + '）',
                            )
                        }

                        const nextSrc = new URL(iframe.src, document.baseURI)
                        nextSrc.searchParams.set('v', Date.now().toString())
                        iframe.src = nextSrc.toString()

                        button.title =
                            '已重新抓取：正文 ' +
                            (data.length ?? '?') +
                            ' 字，图片 ' +
                            (data.images ?? '?') +
                            ' 张'

                        notify(
                            'success',
                            '已重新抓取公众号文章',
                            '正文 ' +
                                (data.length ?? '?') +
                                ' 字，图片 ' +
                                (data.images ?? '?') +
                                ' 张，块内预览已更新。',
                        )
                    } catch (error) {
                        notify(
                            'danger',
                            '重新抓取失败',
                            error && error.message ? error.message : String(error),
                        )
                    } finally {
                        // 只恢复交互态，不重置 title：成功时 title 保留「已重新抓取：正文 X 字…」
                        button.disabled = false
                        button.classList.remove('opacity-50')
                        button.removeAttribute('aria-busy')
                        button.querySelector('svg')?.classList.remove(
                            'wechat-article-spin',
                        )
                    }
                })

                const deleteContainer = header.querySelector(
                    '.fi-fo-rich-editor-custom-block-delete-btn-ctn',
                )

                if (deleteContainer) {
                    header.insertBefore(button, deleteContainer)
                } else {
                    header.appendChild(button)
                }
            })
    }

    registerSaveIndicator()

    return Extension.create({
        name: 'wechatEmbedRefetch',

        addProseMirrorPlugins() {
            return [
                new Plugin({
                    view: (view) => {
                        let runTimer = null
                        const run = () => {
                            try {
                                decorate(view)
                            } catch (error) {
                                // 静默：装饰失败不影响编辑器
                            }
                        }
                        // subtree 观察在每次键入都会触发，加防抖避免块多时输入卡顿
                        const scheduleRun = () => {
                            if (runTimer) clearTimeout(runTimer)
                            runTimer = setTimeout(run, 150)
                        }

                        run()

                        const observer = new MutationObserver(scheduleRun)
                        observer.observe(view.dom, {
                            childList: true,
                            subtree: true,
                        })

                        return {
                            update: scheduleRun,
                            destroy: () => {
                                if (runTimer) clearTimeout(runTimer)
                                observer.disconnect()
                            },
                        }
                    },
                }),
            ]
        },
    })
}
