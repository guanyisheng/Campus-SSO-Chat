'use strict';

const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('CampusChatDesktop', {
  isDesktop: true,
});
