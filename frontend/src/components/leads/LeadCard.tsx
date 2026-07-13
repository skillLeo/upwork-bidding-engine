import Link from "next/link";
import { ShieldCheck, Star, Users } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { ScoreBadge, StatusPill } from "@/components/ui/Badge";
import { relativeTime } from "@/lib/utils";
import type { Lead } from "@/lib/types";

export function LeadCard({
  lead,
  activeFilterId,
}: {
  lead: Lead;
  activeFilterId?: number | null;
}) {
  const href =
    activeFilterId != null ? `/leads/${lead.id}?filter=${activeFilterId}` : `/leads/${lead.id}`;

  return (
    <Link href={href} className="block">
      <Card className="cursor-pointer p-5 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-card-hover">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h3 className="line-clamp-1 font-semibold text-text-primary">{lead.title}</h3>
              {lead.matches_filter === false && (
                <span className="shrink-0 rounded-pill bg-danger-bg px-2 py-0.5 text-[11px] font-semibold text-danger">
                  Not in filter
                </span>
              )}
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              Posted {relativeTime(lead.posted_at)}
              {lead.client_country ? ` · ${lead.client_country}` : ""}
            </p>
          </div>
          <ScoreBadge score={lead.score} />
        </div>

        <p className="mt-3 line-clamp-2 text-sm text-text-secondary">{lead.full_brief}</p>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <StatusPill status={lead.status} />
          {lead.budget && (
            <span className="rounded-pill bg-neutral-bg px-2.5 py-0.5 text-xs font-medium text-neutral">
              {lead.budget}
            </span>
          )}
          <span className="flex items-center gap-1 rounded-pill bg-neutral-bg px-2.5 py-0.5 text-xs font-medium text-neutral">
            <Users className="h-3 w-3" /> {lead.proposal_count} proposals
          </span>
          {lead.payment_verified && (
            <span className="flex items-center gap-1 rounded-pill bg-success-bg px-2.5 py-0.5 text-xs font-medium text-success">
              <ShieldCheck className="h-3 w-3" /> Payment verified
            </span>
          )}
          {lead.client_rating != null && (
            <span className="flex items-center gap-1 rounded-pill bg-warning-bg px-2.5 py-0.5 text-xs font-medium text-warning">
              <Star className="h-3 w-3 fill-current" /> {lead.client_rating}
              {lead.client_reviews != null ? ` (${lead.client_reviews})` : ""}
            </span>
          )}
        </div>
      </Card>
    </Link>
  );
}
