"use client";

import { useMemo } from "react";
import { CopyButton } from "./CopyButton";

type TokenKind = "key" | "string" | "number" | "boolean" | "null" | "punct" | "plain";

const TOKEN_PATTERN =
  /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false)\b|\bnull\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g;

const KIND_CLASS: Record<TokenKind, string> = {
  key: "text-amber-soft",
  string: "text-mint",
  number: "text-sky",
  boolean: "text-lilac",
  null: "text-faint",
  punct: "text-faint",
  plain: "text-muted",
};

function tokenize(json: string) {
  const tokens: { text: string; kind: TokenKind }[] = [];
  let cursor = 0;

  for (const match of json.matchAll(TOKEN_PATTERN)) {
    const index = match.index ?? 0;
    if (index > cursor) {
      tokens.push({ text: json.slice(cursor, index), kind: "plain" });
    }

    const [full, quoted, colon, booleanLiteral] = match;

    if (quoted !== undefined) {
      if (colon !== undefined) {
        tokens.push({ text: quoted, kind: "key" });
        tokens.push({ text: colon, kind: "punct" });
      } else {
        tokens.push({ text: quoted, kind: "string" });
      }
    } else if (booleanLiteral !== undefined) {
      tokens.push({ text: full, kind: "boolean" });
    } else if (full === "null") {
      tokens.push({ text: full, kind: "null" });
    } else {
      tokens.push({ text: full, kind: "number" });
    }

    cursor = index + full.length;
  }

  if (cursor < json.length) {
    tokens.push({ text: json.slice(cursor), kind: "plain" });
  }

  return tokens;
}

export function JsonViewer({ data }: { data: unknown }) {
  const json = useMemo(() => JSON.stringify(data, null, 2) ?? "null", [data]);
  const tokens = useMemo(() => tokenize(json), [json]);

  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-surface">
      <div className="flex items-center justify-between gap-3 border-b border-line bg-panel px-5 py-2.5">
        <span className="font-mono text-[11px] uppercase tracking-widest text-faint">
          response.json
        </span>
        <CopyButton value={json} label="Copy JSON" />
      </div>
      <pre className="max-h-[32rem] overflow-auto p-5 font-mono text-xs leading-relaxed">
        <code>
          {tokens.map((token, index) => (
            <span key={index} className={KIND_CLASS[token.kind]}>
              {token.text}
            </span>
          ))}
        </code>
      </pre>
    </div>
  );
}
