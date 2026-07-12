"use client";

import { cn } from "@/lib/utils";
import type { LeadStatus } from "@/lib/types";

const statusOptions: Array<{ value: LeadStatus | "all"; label: string }> = [
  { value: "all", label: "All leads" },
  { value: "ready", label: "Ready" },
  { value: "sent", label: "Sent" },
  { value: "replied", label: "Replied" },
  { value: "won", label: "Won" },
  { value: "new", label: "New" },
  { value: "scoring", label: "Scoring" },
  { value: "archived", label: "Archived" },
];

const scoreOptions: Array<{ value: number | undefined; label: string }> = [
  { value: undefined, label: "Any" },
  { value: 7, label: "7+" },
  { value: 9, label: "9+" },
];

export function LeadFiltersRail({
  status,
  onStatusChange,
  scoreMin,
  onScoreMinChange,
}: {
  status: string;
  onStatusChange: (status: string) => void;
  scoreMin: number | undefined;
  onScoreMinChange: (score: number | undefined) => void;
}) {
  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-card border border-border bg-surface shadow-card">
        <div className="border-b border-border px-4 py-3">
          <p className="text-sm font-semibold text-text-primary">Status</p>
        </div>
        <div className="flex flex-col gap-0.5 p-2">
          {statusOptions.map((opt) => (
            <button
              key={opt.value}
              onClick={() => onStatusChange(opt.value)}
              className={cn(
                "rounded-md px-3 py-2 text-left text-sm font-medium text-text-secondary transition-colors hover:bg-black/5",
                status === opt.value && "bg-primary-tint text-primary",
              )}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </div>

      <div className="rounded-card border border-border bg-surface p-4 shadow-card">
        <p className="mb-2 text-sm font-semibold text-text-primary">Minimum score</p>
        <div className="flex gap-1.5">
          {scoreOptions.map((opt) => (
            <button
              key={opt.label}
              onClick={() => onScoreMinChange(opt.value)}
              className={cn(
                "flex-1 rounded-pill border px-2 py-1.5 text-xs font-medium transition-colors",
                scoreMin === opt.value
                  ? "border-primary bg-primary-tint text-primary"
                  : "border-border-strong text-text-secondary hover:bg-black/5",
              )}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
