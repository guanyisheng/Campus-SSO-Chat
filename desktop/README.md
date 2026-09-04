# 校园智聊 · 桌面客户端（Electron）

用 [Electron](https://www.electronjs.org/) 把智聊网页嵌进独立窗口，像普通 PC 软件一样使用。

## 使用前

1. 确保智聊 **Web 服务已部署并可访问**（本机或局域网地址均可）
2. 复制配置并修改服务器地址：

```bash
cd desktop
cp config.example.json config.json
# 编辑 config.json，把 appUrl 改成你的智聊地址
```

`appUrl` 示例：

- `https://openai.ynjw.com/`
- `http://192.168.1.33:18481/chat.php`

> **登录 / SSO**：桌面版默认 `allowExternalLogin: true`，统一认证跳转会在应用窗口内完成（不会跳到系统浏览器导致登录态丢失）。Cookie 保存在 `persist:campus-chat` 分区，关闭再开仍保持登录。

> **OIDC 回调**：服务端 `config.php` 的 `SITE_URL` 须与 `appUrl` 域名一致（例如都是 `https://openai.ynjw.com`）。

## 开发运行

```bash
cd desktop
npm install
npm start
```

## 打包安装包

```bash
cd desktop
npm install
npm run dist        # 当前系统自动打包
npm run dist:mac    # macOS (.dmg / .zip)
npm run dist:win    # Windows (.exe 安装包 + portable)
```

产物在 `desktop/dist/`。

## 功能说明

| 功能 | 说明 |
|------|------|
| 嵌套浏览器 | 内嵌 Chromium，完整加载智聊页面 |
| 登录态 | Cookie / Session 保存在 Electron，关闭再开仍登录 |
| 外链 | 非本站链接用系统默认浏览器打开 |
| 菜单 → 设置 | 可随时修改服务器地址 |
| 快捷键 | `Ctrl/Cmd+R` 刷新，`Ctrl/Cmd+Shift+H` 回首页 |

## 目录

```
desktop/
  main.js           # Electron 主进程
  preload.js        # 预加载脚本
  config.json       # 本地配置（勿提交敏感信息）
  config.example.json
  assets/icon.png   # 应用图标
  package.json
```
