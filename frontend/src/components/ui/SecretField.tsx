"use client";

import * as React from "react";
import { CheckCircle2, Eye, EyeOff } from "lucide-react";
import { Input, Label, FieldHint } from "./Input";

interface SecretFieldProps {
  label: string;
  isSet: boolean;
  masked: string | null;
  value: string;
  onChange: (value: string) => void;
  hint?: string;
  testId?: string;
}

/**
 * The API only ever returns a masked preview for secrets (never the real
 * value) — so "Reveal" toggles that preview, and the input itself is always
 * for entering a *replacement* value. Leaving it blank keeps the existing
 * secret untouched (enforced server-side too).
 */
export function SecretField({ label, isSet, masked, value, onChange, hint, testId }: SecretFieldProps) {
  const [revealed, setRevealed] = React.useState(false);

  return (
    <div>
      <div className="flex items-center justify-between gap-2">
        <Label className="mb-0">{label}</Label>
        {isSet && (
          <button
            type="button"
            onClick={() => setRevealed((v) => !v)}
            className="flex items-center gap-1 text-xs font-medium text-text-secondary hover:text-primary"
          >
            {revealed ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
            {revealed ? "Hide" : "Reveal"}
          </button>
        )}
      </div>

      {isSet ? (
        <div className="mb-1.5 flex items-center gap-1.5 text-xs text-text-secondary">
          <CheckCircle2 className="h-3.5 w-3.5 text-success" />
          Currently set:{" "}
          <span className="font-mono text-text-primary">
            {revealed ? masked : "•".repeat(12)}
          </span>
        </div>
      ) : (
        <div className="mb-1.5 text-xs text-text-tertiary">Not configured yet</div>
      )}

      <Input
        type="password"
        autoComplete="off"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={isSet ? "Enter a new value to replace it" : "Enter a value"}
        data-testid={testId}
      />
      {hint && <FieldHint>{hint}</FieldHint>}
    </div>
  );
}
