const clamp = (value) => Math.max(0, Math.min(255, value));

export function parseColor(value) {
  if (typeof value !== 'string') return null;
  const color = value.trim();
  const hex = color.match(/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i);
  if (hex) {
    let digits = hex[1];
    if (digits.length <= 4) digits = [...digits].map((digit) => digit + digit).join('');
    const channels = [0, 2, 4].map((index) => parseInt(digits.slice(index, index + 2), 16));
    const alpha = digits.length === 8 ? parseInt(digits.slice(6, 8), 16) / 255 : 1;
    return [...channels, alpha];
  }
  const rgb = color.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)$/i);
  if (!rgb) return null;
  return [clamp(Number(rgb[1])), clamp(Number(rgb[2])), clamp(Number(rgb[3])),
    rgb[4] === undefined ? 1 : Math.max(0, Math.min(1, Number(rgb[4])))];
}

export function composite(color, background) {
  const alpha = color[3];
  return [0, 1, 2].map((index) => color[index] * alpha + background[index] * (1 - alpha));
}
const luminance = (color) => {
  const channels = color.map((channel) => {
    const normalized = channel / 255;
    return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
  });
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
};

export function contrastRatio(foreground, background) {
  const first = luminance(foreground);
  const second = luminance(background);
  return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

// Keep theme colors when readable, otherwise choose a neutral with sufficient contrast.
export function readableText(preferred, surface, fallbackSurface = '#ffffff') {
  const backdrop = parseColor(fallbackSurface) || [255, 255, 255, 1];
  const surfaceColor = parseColor(surface) || backdrop;
  const background = composite(surfaceColor, backdrop);
  const requested = parseColor(preferred);
  if (requested && contrastRatio(composite(requested, background), background) >= 4.5) {
    return preferred;
  }
  const candidates = ['#f1f5f9', '#17212f', '#ffffff', '#000000'];
  return candidates.find((candidate) => contrastRatio(parseColor(candidate), background) >= 4.5)
    || candidates.reduce((best, candidate) =>
      contrastRatio(parseColor(candidate), background) > contrastRatio(parseColor(best), background)
        ? candidate : best, candidates[0]);
}
