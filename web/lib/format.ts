/** Format a byte count for display, e.g. 2555664241 -> "2.4 GB". */
export function formatBytes(bytes: number | null | undefined): string | null {
  if (!bytes || bytes <= 0) return null;

  const units = ["B", "KB", "MB", "GB", "TB"];
  let value = bytes;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  const rounded = value >= 100 ? Math.round(value) : Number(value.toFixed(1));
  return `${rounded} ${units[unit]}`;
}

/** Format a count with thousands separators, e.g. 14109 -> "14,109". */
export function formatCount(value: number): string {
  return new Intl.NumberFormat("en-US").format(value);
}
