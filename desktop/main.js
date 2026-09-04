'use strict';

const {
  app,
  BrowserWindow,
  Menu,
  shell,
  dialog,
  session,
} = require('electron');
const path = require('path');
const fs = require('fs');

const CONFIG_PATH = path.join(__dirname, 'config.json');
const CONFIG_EXAMPLE = path.join(__dirname, 'config.example.json');
const DEFAULT_URL = 'https://openai.ynjw.com/';

let mainWindow = null;
let appConfig = loadConfig();

function loadConfig() {
  const defaults = {
    appUrl: DEFAULT_URL,
    windowTitle: '校园智聊',
    width: 1180,
    height: 820,
    minWidth: 900,
    minHeight: 640,
    allowExternalLogin: true,
    allowedOrigins: ['https://openai.ynjw.com'],
  };

  try {
    if (!fs.existsSync(CONFIG_PATH) && fs.existsSync(CONFIG_EXAMPLE)) {
      fs.copyFileSync(CONFIG_EXAMPLE, CONFIG_PATH);
    }
    if (fs.existsSync(CONFIG_PATH)) {
      const raw = fs.readFileSync(CONFIG_PATH, 'utf8');
      const json = JSON.parse(raw);
      return {
        appUrl: normalizeUrl(json.appUrl || defaults.appUrl),
        windowTitle: String(json.windowTitle || defaults.windowTitle).trim() || defaults.windowTitle,
        width: Number(json.width) || defaults.width,
        height: Number(json.height) || defaults.height,
        minWidth: Number(json.minWidth) || defaults.minWidth,
        minHeight: Number(json.minHeight) || defaults.minHeight,
        allowExternalLogin: json.allowExternalLogin !== false,
        allowedOrigins: normalizeAllowedOrigins(json.allowedOrigins, json.appUrl || defaults.appUrl),
      };
    }
  } catch (err) {
    console.error('读取 config.json 失败:', err);
  }

  return {
    ...defaults,
    appUrl: normalizeUrl(defaults.appUrl),
    allowedOrigins: normalizeAllowedOrigins(defaults.allowedOrigins, defaults.appUrl),
  };
}

function saveConfig(next) {
  appConfig = { ...appConfig, ...next };
  fs.writeFileSync(
    CONFIG_PATH,
    JSON.stringify(
      {
        appUrl: appConfig.appUrl,
        windowTitle: appConfig.windowTitle,
        width: appConfig.width,
        height: appConfig.height,
        minWidth: appConfig.minWidth,
        minHeight: appConfig.minHeight,
        allowExternalLogin: appConfig.allowExternalLogin,
        allowedOrigins: appConfig.allowedOrigins,
      },
      null,
      2
    ) + '\n',
    'utf8'
  );
}

function normalizeUrl(input) {
  const raw = String(input || '').trim();
  if (!raw) return DEFAULT_URL;
  try {
    const u = new URL(raw);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') {
      return DEFAULT_URL;
    }
    return u.toString();
  } catch (_) {
    return DEFAULT_URL;
  }
}

function normalizeAllowedOrigins(list, appUrl) {
  const out = new Set();
  try {
    out.add(new URL(normalizeUrl(appUrl)).origin);
  } catch (_) {}
  if (Array.isArray(list)) {
    list.forEach((item) => {
      const s = String(item || '').trim();
      if (!s) return;
      try {
        if (s.includes('://')) {
          out.add(new URL(s).origin);
        } else {
          out.add(new URL('https://' + s.replace(/^\/+/, '')).origin);
        }
      } catch (_) {}
    });
  }
  return Array.from(out);
}

function getAppOrigin() {
  try {
    return new URL(appConfig.appUrl).origin;
  } catch (_) {
    return '';
  }
}

/** 是否允许在应用窗口内跳转（含 SSO 登录页） */
function isInAppNavigation(urlString) {
  try {
    const target = new URL(urlString);
    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
      return false;
    }
    if (appConfig.allowedOrigins.includes(target.origin)) {
      return true;
    }
    // 统一认证 / OIDC 通常会跳到 IdP 域名，需在窗口内完成后再跳回
    if (appConfig.allowExternalLogin) {
      return true;
    }
    return false;
  } catch (_) {
    return false;
  }
}

function attachWebContentsHandlers(webContents) {
  webContents.setWindowOpenHandler(({ url }) => {
    if (!url) return { action: 'deny' };
    if (isInAppNavigation(url)) {
      return {
        action: 'allow',
        overrideBrowserWindowOptions: {
          width: appConfig.width,
          height: appConfig.height,
          autoHideMenuBar: true,
          webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            spellcheck: false,
          },
        },
      };
    }
    shell.openExternal(url);
    return { action: 'deny' };
  });

  webContents.on('will-navigate', (event, url) => {
    if (!isInAppNavigation(url)) {
      event.preventDefault();
      shell.openExternal(url);
    }
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: appConfig.width,
    height: appConfig.height,
    minWidth: appConfig.minWidth,
    minHeight: appConfig.minHeight,
    title: appConfig.windowTitle,
    icon: path.join(__dirname, 'assets', 'icon.png'),
    backgroundColor: '#0f0f0f',
    autoHideMenuBar: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      spellcheck: false,
      partition: 'persist:campus-chat',
    },
  });

  attachWebContentsHandlers(mainWindow.webContents);

  mainWindow.webContents.on('did-create-window', (childWindow) => {
    attachWebContentsHandlers(childWindow.webContents);
    childWindow.webContents.on('will-navigate', (_event, url) => {
      if (isInAppNavigation(url) && getAppOrigin() && url.startsWith(getAppOrigin())) {
        mainWindow.loadURL(url);
        childWindow.close();
      }
    });
  });

  mainWindow.loadURL(appConfig.appUrl).catch((err) => {
    dialog.showErrorBox(
      '无法打开智聊',
      '请检查 desktop/config.json 中的 appUrl 是否正确。\n\n' +
        String(err && err.message ? err.message : err)
    );
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

function reloadHome() {
  if (!mainWindow) return;
  mainWindow.loadURL(appConfig.appUrl);
}

async function promptChangeServer() {
  const next = await promptUrlFallback();
  if (!next) return;
  const appUrl = normalizeUrl(next);
  saveConfig({
    appUrl,
    allowedOrigins: normalizeAllowedOrigins(appConfig.allowedOrigins, appUrl),
  });
  reloadHome();
}

async function promptUrlFallback() {
  return new Promise((resolve) => {
    const win = new BrowserWindow({
      width: 520,
      height: 220,
      resizable: false,
      minimizable: false,
      maximizable: false,
      parent: mainWindow || undefined,
      modal: !!mainWindow,
      title: '服务器地址',
      webPreferences: {
        nodeIntegration: true,
        contextIsolation: false,
      },
    });
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>服务器地址</title>
<style>body{font:14px system-ui,sans-serif;margin:20px;color:#111}input{width:100%;box-sizing:border-box;padding:8px 10px;margin:8px 0 14px;border:1px solid #ccc;border-radius:8px}button{padding:8px 14px;border-radius:8px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer;margin-right:8px}</style></head>
<body><label>智聊服务器地址</label>
<input id="url" value="${appConfig.appUrl.replace(/"/g, '&quot;')}" autofocus>
<div><button id="ok">确定</button><button id="cancel">取消</button></div>
<script>const {ipcRenderer}=require('electron');document.getElementById('ok').onclick=()=>ipcRenderer.send('url-ok',document.getElementById('url').value);document.getElementById('cancel').onclick=()=>ipcRenderer.send('url-cancel');document.getElementById('url').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('ok').click();});</script></body></html>`;
    win.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
    const { ipcMain } = require('electron');
    const onOk = (_e, value) => {
      cleanup();
      resolve(String(value || '').trim());
      win.close();
    };
    const onCancel = () => {
      cleanup();
      resolve('');
      win.close();
    };
    const cleanup = () => {
      ipcMain.removeListener('url-ok', onOk);
      ipcMain.removeListener('url-cancel', onCancel);
    };
    ipcMain.on('url-ok', onOk);
    ipcMain.on('url-cancel', onCancel);
    win.on('closed', () => {
      cleanup();
      resolve('');
    });
  });
}

function buildMenu() {
  const template = [
    ...(process.platform === 'darwin'
      ? [
          {
            label: appConfig.windowTitle,
            submenu: [
              { role: 'about' },
              { type: 'separator' },
              { role: 'hide' },
              { role: 'hideOthers' },
              { role: 'unhide' },
              { type: 'separator' },
              { role: 'quit', label: '退出校园智聊' },
            ],
          },
        ]
      : []),
    {
      label: '文件',
      submenu: [
        {
          label: '重新加载',
          accelerator: 'CmdOrCtrl+R',
          click: () => mainWindow && mainWindow.webContents.reload(),
        },
        {
          label: '回到首页',
          accelerator: 'CmdOrCtrl+Shift+H',
          click: reloadHome,
        },
        { type: 'separator' },
        process.platform === 'darwin' ? { role: 'close' } : { role: 'quit', label: '退出' },
      ],
    },
    {
      label: '设置',
      submenu: [
        {
          label: '修改服务器地址…',
          click: () => promptChangeServer(),
        },
        {
          label: '在系统浏览器中打开',
          click: () => shell.openExternal(appConfig.appUrl),
        },
      ],
    },
    {
      label: '视图',
      submenu: [
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' },
        { type: 'separator' },
        { role: 'togglefullscreen' },
      ],
    },
    {
      label: '帮助',
      submenu: [
        {
          label: '开发者工具',
          accelerator: process.platform === 'darwin' ? 'Alt+Command+I' : 'Ctrl+Shift+I',
          click: () => mainWindow && mainWindow.webContents.toggleDevTools(),
        },
      ],
    },
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function stripElectronFromUserAgent() {
  const ses = session.fromPartition('persist:campus-chat');
  const ua = ses.getUserAgent().replace(/\sElectron\/[^\s]+/g, '').replace(/\sCampusChatDesktop\/[^\s]+/g, '');
  ses.setUserAgent(ua);
}

app.whenReady().then(() => {
  stripElectronFromUserAgent();
  session.fromPartition('persist:campus-chat').setPermissionRequestHandler((_wc, _permission, callback) => {
    callback(true);
  });
  buildMenu();
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
