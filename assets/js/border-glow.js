/**
 * BorderGlow — vanilla port (React Bits)
 */
const GRADIENT_POSITIONS = ['80% 55%', '69% 34%', '8% 6%', '41% 38%', '86% 85%', '82% 18%', '51% 4%'];
const GRADIENT_KEYS = [
  '--gradient-one', '--gradient-two', '--gradient-three', '--gradient-four',
  '--gradient-five', '--gradient-six', '--gradient-seven',
];
const COLOR_MAP = [0, 1, 2, 0, 1, 2, 1];

const PRESETS = {
  composer: {
    edgeSensitivity: 28,
    glowColor: '160 70 55',
    borderRadius: 28,
    glowRadius: 28,
    glowIntensity: 0.9,
    coneSpread: 22,
    animated: false,
    colors: ['#10A37F', '#c084fc', '#38bdf8'],
    fillOpacity: 0.42,
  },
  msg: {
    edgeSensitivity: 70,
    glowColor: '40 80 80',
    backgroundColor: '#120F17',
    borderRadius: 20,
    glowRadius: 24,
    glowIntensity: 2.4,
    coneSpread: 36,
    animated: false,
    colors: ['#c084fc', '#f472b6', '#38bdf8'],
    fillOpacity: 0.5,
  },
  auth: {
    edgeSensitivity: 30,
    glowColor: '40 80 80',
    borderRadius: 20,
    glowRadius: 32,
    glowIntensity: 1,
    coneSpread: 25,
    animated: true,
    colors: ['#c084fc', '#f472b6', '#38bdf8'],
    fillOpacity: 0.5,
  },
};

function parseHSL(hslStr) {
  const match = String(hslStr).match(/([\d.]+)\s+([\d.]+)%?\s+([\d.]+)%?/);
  if (!match) return { h: 40, s: 80, l: 80 };
  return { h: parseFloat(match[1]), s: parseFloat(match[2]), l: parseFloat(match[3]) };
}

function buildGlowVars(glowColor, intensity) {
  const { h, s, l } = parseHSL(glowColor);
  const base = `${h}deg ${s}% ${l}%`;
  const opacities = [100, 60, 50, 40, 30, 20, 10];
  const keys = ['', '-60', '-50', '-40', '-30', '-20', '-10'];
  const vars = {};
  for (let i = 0; i < opacities.length; i++) {
    vars[`--glow-color${keys[i]}`] = `hsl(${base} / ${Math.min(opacities[i] * intensity, 100)}%)`;
  }
  return vars;
}

function buildGradientVars(colors) {
  const list = colors && colors.length ? colors : ['#c084fc', '#f472b6', '#38bdf8'];
  const vars = {};
  for (let i = 0; i < 7; i++) {
    const c = list[Math.min(COLOR_MAP[i], list.length - 1)];
    vars[GRADIENT_KEYS[i]] = `radial-gradient(at ${GRADIENT_POSITIONS[i]}, ${c} 0px, transparent 50%)`;
  }
  vars['--gradient-base'] = `linear-gradient(${list[0]} 0 100%)`;
  return vars;
}

function easeOutCubic(x) { return 1 - Math.pow(1 - x, 3); }
function easeInCubic(x) { return x * x * x; }

function animateValue({ start = 0, end = 100, duration = 1000, delay = 0, ease = easeOutCubic, onUpdate, onEnd }) {
  const t0 = performance.now() + delay;
  function tick() {
    const elapsed = performance.now() - t0;
    const t = Math.min(elapsed / duration, 1);
    onUpdate(start + (end - start) * ease(t));
    if (t < 1) requestAnimationFrame(tick);
    else if (onEnd) onEnd();
  }
  setTimeout(function () { requestAnimationFrame(tick); }, delay);
}

function getCenterOfElement(el) {
  const { width, height } = el.getBoundingClientRect();
  return [width / 2, height / 2];
}

function getEdgeProximity(el, x, y) {
  const [cx, cy] = getCenterOfElement(el);
  const dx = x - cx;
  const dy = y - cy;
  let kx = Infinity;
  let ky = Infinity;
  if (dx !== 0) kx = cx / Math.abs(dx);
  if (dy !== 0) ky = cy / Math.abs(dy);
  return Math.min(Math.max(1 / Math.min(kx, ky), 0), 1);
}

function getCursorAngle(el, x, y) {
  const [cx, cy] = getCenterOfElement(el);
  const dx = x - cx;
  const dy = y - cy;
  if (dx === 0 && dy === 0) return 0;
  const radians = Math.atan2(dy, dx);
  let degrees = radians * (180 / Math.PI) + 90;
  if (degrees < 0) degrees += 360;
  return degrees;
}

function applyOptions(card, options) {
  const glowVars = buildGlowVars(options.glowColor, options.glowIntensity);
  const gradientVars = buildGradientVars(options.colors);
  const style = card.style;
  style.setProperty('--edge-sensitivity', String(options.edgeSensitivity));
  style.setProperty('--border-radius', options.borderRadius + 'px');
  style.setProperty('--glow-padding', options.glowRadius + 'px');
  style.setProperty('--cone-spread', String(options.coneSpread));
  style.setProperty('--fill-opacity', String(options.fillOpacity));
  if (options.backgroundColor) {
    style.setProperty('--card-bg', options.backgroundColor);
  }
  Object.keys(glowVars).forEach(function (k) { style.setProperty(k, glowVars[k]); });
  Object.keys(gradientVars).forEach(function (k) { style.setProperty(k, gradientVars[k]); });
}

function runSweepAnimation(card) {
  const angleStart = 110;
  const angleEnd = 465;
  card.classList.add('sweep-active');
  card.style.setProperty('--cursor-angle', angleStart + 'deg');
  animateValue({ duration: 500, onUpdate: function (v) { card.style.setProperty('--edge-proximity', String(v)); } });
  animateValue({
    ease: easeInCubic,
    duration: 1500,
    end: 50,
    onUpdate: function (v) {
      card.style.setProperty('--cursor-angle', ((angleEnd - angleStart) * (v / 100) + angleStart) + 'deg');
    },
  });
  animateValue({
    ease: easeOutCubic,
    delay: 1500,
    duration: 2250,
    start: 50,
    end: 100,
    onUpdate: function (v) {
      card.style.setProperty('--cursor-angle', ((angleEnd - angleStart) * (v / 100) + angleStart) + 'deg');
    },
  });
  animateValue({
    ease: easeInCubic,
    delay: 2500,
    duration: 1500,
    start: 100,
    end: 0,
    onUpdate: function (v) { card.style.setProperty('--edge-proximity', String(v)); },
    onEnd: function () { card.classList.remove('sweep-active'); },
  });
}

export function initBorderGlow(card, options) {
  if (!card) return function () {};

  applyOptions(card, options);

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return function () {};
  }

  function onPointerMove(e) {
    const rect = card.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    const edge = getEdgeProximity(card, x, y);
    const angle = getCursorAngle(card, x, y);
    card.style.setProperty('--edge-proximity', (edge * 100).toFixed(3));
    card.style.setProperty('--cursor-angle', angle.toFixed(3) + 'deg');
  }

  card.addEventListener('pointermove', onPointerMove);

  if (options.animated) {
    runSweepAnimation(card);
  }

  return function destroy() {
    card.removeEventListener('pointermove', onPointerMove);
  };
}

function mountBorderGlowCard(card) {
  if (!card || card.dataset.borderGlowReady) return;
  card.dataset.borderGlowReady = '1';
  const presetName = card.dataset.borderGlowPreset || 'auth';
  const preset = Object.assign({}, PRESETS[presetName] || PRESETS.auth);
  if (card.dataset.borderGlowAnimated === 'true') preset.animated = true;
  if (card.dataset.borderGlowAnimated === 'false') preset.animated = false;
  initBorderGlow(card, preset);
}

function mountBorderGlowCards(root) {
  const scope = root || document;
  scope.querySelectorAll('[data-border-glow]').forEach(mountBorderGlowCard);
}

function initBorderGlowCards() {
  mountBorderGlowCards(document);
}

window.mountBorderGlowCards = mountBorderGlowCards;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initBorderGlowCards);
} else {
  initBorderGlowCards();
}
