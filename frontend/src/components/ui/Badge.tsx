import * as React from "react";
import { cn } from "@/lib/utils";
import type { ClientStage, LeadStatus } from "@/lib/types";

export type BadgeTone = "success" | "info" | "neutral" | "warning" | "danger";

const toneClasses: Record<BadgeTone, string> = {
  success: "bg-success-bg text-success border-success-border",
  info: "bg-info-bg text-info border-info-border",
  neutral: "bg-neutral-bg text-neutral border-neutral-border",
  warning: "bg-warning-bg text-warning border-warning-border",
  danger: "bg-danger-bg text-danger border-danger-border",
};

export function Badge({
  tone = "neutral",
  className,
  children,
}: {
  tone?: BadgeTone;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 whitespace-nowrap rounded-pill border px-2.5 py-0.5 text-xs font-semibold",
        toneClasses[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}

export function ScoreBadge({ score }: { score: number | null }) {
  if (score === null) return <Badge tone="neutral">Not scored</Badge>;
  const tone: BadgeTone = score >= 9 ? "success" : score >= 7 ? "info" : "neutral";
  return <Badge tone={tone}>{score}/10</Badge>;
}

const statusLabels: Record<LeadStatus, string> = {
  new: "New",
  scoring: "Scoring",
  ready: "Ready",
  sent: "Sent",
  replied: "Replied",
  won: "Won",
  archived: "Archived",
};

const statusTones: Record<LeadStatus, BadgeTone> = {
  new: "neutral",
  scoring: "warning",
  ready: "info",
  sent: "info",
  replied: "warning",
  won: "success",
  archived: "neutral",
};

export function StatusPill({ status }: { status: LeadStatus }) {
  return <Badge tone={statusTones[status]}>{statusLabels[status]}</Badge>;
}

const stageLabels: Record<ClientStage, string> = {
  new: "New",
  talking: "Talking",
  negotiating: "Negotiating",
  closing: "Closing",
  won: "Won",
  lost: "Lost",
};

const stageTones: Record<ClientStage, BadgeTone> = {
  new: "neutral",
  talking: "info",
  negotiating: "warning",
  closing: "warning",
  won: "success",
  lost: "danger",
};

export function StagePill({ stage }: { stage: ClientStage }) {
  return <Badge tone={stageTones[stage]}>{stageLabels[stage]}</Badge>;
}
