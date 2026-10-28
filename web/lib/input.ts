export type ParsedInput =
  | { kind: "url"; url: string }
  | { kind: "query"; query: string }
  | { kind: "invalid-url" };

/**
 * Decide whether the search box got a detail page URL or a title.
 * Bare hosts ("movielinkbd.tv/movie/x") count as URLs too.
 */
export function parseInput(raw: string): ParsedInput {
  const value = raw.trim();
  if (!value) return { kind: "query", query: "" };

  const hasScheme = /^https?:\/\//i.test(value);
  const looksLikeHost = /^[a-z0-9-]+(\.[a-z0-9-]+)+\//i.test(value);

  if (hasScheme || looksLikeHost) {
    try {
      const url = new URL(hasScheme ? value : `https://${value}`);
      if (url.protocol === "http:" || url.protocol === "https:") {
        return { kind: "url", url: url.toString() };
      }
    } catch {
      return { kind: "invalid-url" };
    }
    return { kind: "invalid-url" };
  }

  return { kind: "query", query: value };
}
