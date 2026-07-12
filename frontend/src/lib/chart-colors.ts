/**
 * Chart-specific color slots, validated with the dataviz skill's palette
 * checker (CVD ΔE, chroma floor, lightness band) rather than eyeballed.
 * Plain hex (not CSS var()) — some recharts internals don't resolve custom
 * properties reliably inside inline SVG.
 */
export const CHART_COLORS = {
  received: "#1baf7a",
  sent: "#0A66C2",
  replied: "#eda100",
  won: "#057642",
} as const;

export const CHART_CHROME = {
  grid: "#e1e0d9",
  axis: "#898781",
  textPrimary: "rgba(0,0,0,0.9)",
  textSecondary: "rgba(0,0,0,0.6)",
  border: "rgba(0,0,0,0.08)",
} as const;
