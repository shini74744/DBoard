# 后台浏览器回归检查

`admin-user-create.cjs` 使用实际后台资源，API 全部在浏览器中模拟，不连接生产环境或创建真实用户。

准备 Node.js、Playwright 与 Chromium 后，在仓库根目录执行：

~~~bash
npm install --prefix /tmp/dboard-ui-deps --no-save playwright
/tmp/dboard-ui-deps/node_modules/.bin/playwright install chromium
NODE_PATH=/tmp/dboard-ui-deps/node_modules node panel/tests/Browser/admin-user-create.cjs
~~~

可设置 `SCREENSHOT_DIR` 为已有目录，保存各场景的完成截图。

覆盖手机和桌面、普通模式和翻译改写模式、选择套餐、连续添加、批量生成及 CSV 下载。翻译模式会把文字节点替换为嵌套 `font` 元素，并忽略 `translate=no`；测试通过不能依赖禁止翻译。

此前裸文字经 Select Portal 插入触发器，翻译后卸载会发生 `removeChild`；提交按钮在裸文字前插入加载图标时也会发生 `insertBefore`。选中项、占位文字和按钮文字均使用稳定的 React `span` 容器。不要通过改写全局 DOM 删除/插入方法吞掉异常。

当前仓库存放编译后的后台资源。更新上游后台包时，应保留或在源码中重做上述容器处理，以及 i18next 语言变更同步 HTML `lang` 的处理，并重新运行此测试。

完整邮箱识别框的组件源码位于 `panel/resources/js/dboard-email-autofill-component.js`，嵌入编译后台的创建用户表单。修改时保持源码与 bundle 同步。回归同时检查完整邮箱拆分、mailto 前缀、首尾空格、无效输入不覆盖原值，以及从批量切回单用户时清理批量参数。
