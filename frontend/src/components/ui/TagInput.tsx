"use client";

import * as React from "react";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";

export function TagInput({
  value,
  onChange,
  placeholder,
  disabled,
  testId,
}: {
  value: string[];
  onChange: (tags: string[]) => void;
  placeholder?: string;
  disabled?: boolean;
  testId?: string;
}) {
  const [draft, setDraft] = React.useState("");

  function commitDraft() {
    const trimmed = draft.trim();
    if (trimmed && !value.includes(trimmed)) {
      onChange([...value, trimmed]);
    }
    setDraft("");
  }

  function removeTag(tag: string) {
    onChange(value.filter((t) => t !== tag));
  }

  return (
    <div
      className={cn(
        "flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-md border border-border-strong bg-white px-2.5 py-1.5",
        "focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20",
        disabled && "cursor-not-allowed bg-black/5",
      )}
    >
      {value.map((tag) => (
        <span
          key={tag}
          className="inline-flex items-center gap-1 rounded-pill bg-primary-tint px-2 py-1 text-xs font-medium text-primary"
        >
          {tag}
          {!disabled && (
            <button
              type="button"
              onClick={() => removeTag(tag)}
              className="rounded-full hover:text-primary-pressed"
              aria-label={`Remove ${tag}`}
            >
              <X className="h-3 w-3" />
            </button>
          )}
        </span>
      ))}
      {!disabled && (
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" || e.key === ",") {
              e.preventDefault();
              commitDraft();
            } else if (e.key === "Backspace" && draft === "" && value.length > 0) {
              removeTag(value[value.length - 1]);
            }
          }}
          onBlur={commitDraft}
          placeholder={value.length === 0 ? placeholder : ""}
          data-testid={testId}
          className="min-w-[120px] flex-1 border-none bg-transparent py-1 text-sm outline-none placeholder:text-text-tertiary"
        />
      )}
    </div>
  );
}
