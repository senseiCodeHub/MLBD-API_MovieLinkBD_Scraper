"use client";

import { useEffect, useRef, useState } from "react";
import { cx } from "@/lib/cx";
import { CheckIcon, CopyIcon } from "./icons";

type CopyButtonProps = {
  value: string;
  label?: string;
  className?: string;
};

export function CopyButton({ value, label, className }: CopyButtonProps) {
  const [copied, setCopied] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, []);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value);
    } catch {
      const textarea = document.createElement("textarea");
      textarea.value = value;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "fixed";
      textarea.style.opacity = "0";
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand("copy");
      document.body.removeChild(textarea);
    }

    setCopied(true);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopied(false), 1600);
  }

  return (
    <button
      type="button"
      onClick={handleCopy}
      title={copied ? "Copied" : `Copy ${label ?? "value"}`}
      aria-label={copied ? "Copied to clipboard" : `Copy ${label ?? "value"}`}
      className={cx(
        "inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-xl border px-2 text-xs font-medium transition-colors duration-200",
        label ? "min-w-[4.5rem]" : "w-8",
        copied
          ? "border-mint/30 bg-mint/10 text-mint"
          : "border-line bg-transparent text-muted hover:border-line-strong hover:bg-raised hover:text-ink",
        className
      )}
    >
      {copied ? <CheckIcon size={14} /> : <CopyIcon size={14} />}
      {label && <span>{copied ? "Copied" : label}</span>}
    </button>
  );
}
