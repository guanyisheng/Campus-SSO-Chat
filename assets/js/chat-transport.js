/**
 * 对话消息传输编码 — 避免 HTML/代码中的 <> 等被 WAF 误拦
 */
(function () {
  'use strict';

  function messageNeedsTransportEncoding(text) {
    const t = String(text || '');
    if (!t) return false;
    if (/```[\s\S]*```/.test(t)) return true;
    if (/<\?(?:php|=)/i.test(t)) return true;
    if (/<!DOCTYPE|<html[\s>]|<head[\s>]|<body[\s>]|<script[\s>]|<\/[a-z]/i.test(t)) return true;
    if (/<[a-zA-Z][^>]{0,240}>/.test(t)) return true;
    const lt = (t.match(/</g) || []).length;
    const gt = (t.match(/>/g) || []).length;
    return lt >= 2 && gt >= 2;
  }

  function utf8ToBase64(str) {
    return btoa(
      encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, function (_, hex) {
        return String.fromCharCode(parseInt(hex, 16));
      })
    );
  }

  function encodeMessageForTransport(msg) {
    if (!msg || typeof msg.content !== 'string') return msg;
    if (!messageNeedsTransportEncoding(msg.content)) return msg;
    try {
      return {
        role: msg.role,
        content: utf8ToBase64(msg.content),
        content_encoding: 'base64',
      };
    } catch (_) {
      return msg;
    }
  }

  function prepareMessagesForApi(messages) {
    return (messages || []).map(encodeMessageForTransport);
  }

  window.CampusChatTransport = {
    prepareMessagesForApi: prepareMessagesForApi,
  };
})();
