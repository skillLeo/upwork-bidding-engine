"use client";

import * as React from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import {
  AlertTriangle,
  ArrowLeft,
  Copy,
  ExternalLink,
  Globe2,
  MessageSquare,
  RefreshCw,
  ShieldCheck,
  ShieldOff,
  Star,
  Users,
  Wallet,
} from "lucide-react";
import { useLead, updateLeadStatus, rescoreLead } from "@/lib/hooks/useLead";
import { useSavedFilters } from "@/lib/hooks/useSavedFilters";
import { PageContainer } from "@/components/layout/Rails";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ScoreBadge, StatusPill } from "@/components/ui/Badge";
import { Skeleton, SkeletonText } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { relativeTime } from "@/lib/utils";
import { apiErrorMessage } from "@/lib/api-client";
import { useAuthStore, isAdmin } from "@/stores/auth-store";
import type { LeadStatus } from "@/lib/types";

const statusActions: Array<{ status: LeadStatus; label: string }> = [
  { status: "sent", label: "Mark Sent" },
  { status: "replied", label: "Mark Replied" },
  { status: "won", label: "Mark Won" },
  { status: "archived", label: "Archive" },
];

export default function LeadDetailPage() {
  const params = useParams<{ id: string }>();
  const searchParams = useSearchParams();
  const router = useRouter();
  const user = useAuthStore((s) => s.user);

  const filterId = searchParams.get("filter");
  const { filters: savedFilters } = useSavedFilters();
  const activeFilter = filterId ? savedFilters.find((f) => f.id === Number(filterId)) : undefined;

  const { lead, isLoading, mutate } = useLead(params.id, activeFilter?.criteria);
  const [actionLoading, setActionLoading] = React.useState<string | null>(null);

  async function handleStatus(status: LeadStatus) {
    if (!lead) return;
    setActionLoading(status);
    try {
      const updated = await updateLeadStatus(lead.id, status);
      mutate({ data: updated }, { revalidate: false });
      toast.success(`Lead marked ${status}.`);
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not update status."));
    } finally {
      setActionLoading(null);
    }
  }

  async function handleRescore() {
    if (!lead) return;
    setActionLoading("rescore");
    try {
      const updated = await rescoreLead(lead.id);
      mutate({ data: updated }, { revalidate: false });
      toast.success("Rescoring started — check back shortly.");
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not rescore."));
    } finally {
      setActionLoading(null);
    }
  }

  async function handleCopy() {
    if (!lead?.proposal_text) return;
    await navigator.clipboard.writeText(lead.proposal_text);
    toast.success("Proposal copied to clipboard.");
  }

  if (isLoading) {
    return (
      <PageContainer className="max-w-[760px]">
        <Skeleton className="mb-4 h-5 w-32" />
        <Card className="p-6">
          <SkeletonText lines={6} />
        </Card>
      </PageContainer>
    );
  }

  if (!lead) {
    return (
      <PageContainer className="max-w-[760px]">
        <EmptyState
          title="Lead not found"
          description="It may have been removed."
          action={<Button onClick={() => router.push("/leads")}>Back to leads</Button>}
        />
      </PageContainer>
    );
  }

  return (
    <PageContainer className="max-w-[760px]">
      <Link
        href="/leads"
        className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
      >
        <ArrowLeft className="h-4 w-4" /> Back to leads
      </Link>

      <Card className="p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-xl font-semibold text-text-primary">{lead.title}</h1>
            <p className="mt-1 text-xs text-text-tertiary">
              Posted {relativeTime(lead.posted_at)} · External ID {lead.external_id}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <StatusPill status={lead.status} />
            <ScoreBadge score={lead.score} />
          </div>
        </div>

        {lead.url && (
          <a
            href={lead.url}
            target="_blank"
            rel="noreferrer"
            className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
          >
            View on Upwork <ExternalLink className="h-3.5 w-3.5" />
          </a>
        )}

        {lead.matches_filter === false && lead.filter_fail_reasons && (
          <div className="mt-4 rounded-md border border-danger-border bg-danger-bg px-4 py-3">
            <p className="flex items-center gap-1.5 text-sm font-semibold text-danger">
              <AlertTriangle className="h-4 w-4" /> Why this job isn&apos;t in your filter
            </p>
            <ul className="mt-2 space-y-1">
              {lead.filter_fail_reasons.map((reason) => (
                <li key={reason} className="flex gap-1.5 text-sm text-danger/90">
                  <span aria-hidden>•</span>
                  {reason}
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <StatBlock icon={Wallet} label="Budget" value={lead.budget ?? "—"} />
          <StatBlock icon={Globe2} label="Client country" value={lead.client_country ?? "—"} />
          <StatBlock icon={Users} label="Proposals so far" value={String(lead.proposal_count)} />
          <StatBlock icon={Wallet} label="Client spend" value={lead.client_spend ?? "—"} />
          <StatBlock icon={Users} label="Hire rate" value={lead.client_hire_rate ?? "—"} />
          <StatBlock
            icon={lead.payment_verified ? ShieldCheck : ShieldOff}
            label="Payment"
            value={lead.payment_verified ? "Verified" : "Unverified"}
            tone={lead.payment_verified ? "success" : "neutral"}
          />
          <StatBlock
            icon={Star}
            label="Client rating"
            value={
              lead.client_rating != null
                ? `${lead.client_rating}${lead.client_reviews != null ? ` (${lead.client_reviews} reviews)` : ""}`
                : "—"
            }
          />
        </div>

        <div className="mt-5 border-t border-border pt-5">
          <p className="text-sm font-semibold text-text-primary">Full brief</p>
          <p className="mt-2 text-sm whitespace-pre-wrap text-text-secondary">{lead.full_brief}</p>
        </div>
      </Card>

      {lead.score !== null && (
        <Card className="mt-4 p-6">
          <div className="flex items-center gap-2">
            <ScoreBadge score={lead.score} />
            <p className="text-sm font-semibold text-text-primary">Why this score</p>
          </div>
          <p className="mt-2 text-sm text-text-secondary">{lead.score_reason}</p>
        </Card>
      )}

      {lead.proposal_text && (
        <Card className="mt-4 p-6">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-text-primary">Proposal</p>
            <Button variant="secondary" size="sm" onClick={handleCopy}>
              <Copy className="h-3.5 w-3.5" /> Copy
            </Button>
          </div>
          <p className="mt-3 rounded-md bg-neutral-bg p-4 text-sm whitespace-pre-wrap text-text-primary">
            {lead.proposal_text}
          </p>
        </Card>
      )}

      <Card className="mt-4 p-6">
        <p className="mb-3 text-sm font-semibold text-text-primary">Actions</p>
        <div className="flex flex-wrap gap-2">
          {statusActions.map((action) => (
            <Button
              key={action.status}
              variant={lead.status === action.status ? "primary" : "secondary"}
              size="sm"
              disabled={lead.status === action.status || actionLoading !== null}
              loading={actionLoading === action.status}
              onClick={() => handleStatus(action.status)}
            >
              {action.label}
            </Button>
          ))}
          {isAdmin(user) && (
            <Button
              variant="ghost"
              size="sm"
              disabled={actionLoading !== null}
              loading={actionLoading === "rescore"}
              onClick={handleRescore}
            >
              <RefreshCw className="h-3.5 w-3.5" /> Rescore
            </Button>
          )}
        </div>

        {lead.client_id && (
          <Link
            href={`/clients/${lead.client_id}`}
            className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
          >
            <MessageSquare className="h-4 w-4" /> Open client memory
          </Link>
        )}
      </Card>
    </PageContainer>
  );
}

function StatBlock({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
  tone?: "success" | "neutral";
}) {
  return (
    <div className="rounded-md bg-neutral-bg p-3">
      <div className="flex items-center gap-1.5 text-xs font-medium text-text-tertiary">
        <Icon className={tone === "success" ? "h-3.5 w-3.5 text-success" : "h-3.5 w-3.5"} />
        {label}
      </div>
      <p className="mt-1 text-sm font-medium text-text-primary">{value}</p>
    </div>
  );
}
