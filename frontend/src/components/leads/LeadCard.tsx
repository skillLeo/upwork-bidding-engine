import Link from "next/link";
import { ShieldCheck, Users } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { ScoreBadge, StatusPill } from "@/components/ui/Badge";
import { relativeTime } from "@/lib/utils";
import type { Lead } from "@/lib/types";

export function LeadCard({ lead }: { lead: Lead }) {
  return (
    <Link href={`/leads/${lead.id}`} className="block">
      <Card className="cursor-pointer p-5 transition-shadow hover:shadow-card-hover">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <h3 className="line-clamp-1 font-semibold text-text-primary">{lead.title}</h3>
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
            <span className="flex items-center gap-1 rounded-pill bg-neutral-bg px-2.5 py-0.5 text-xs font-medium text-neutral">
              <ShieldCheck className="h-3 w-3" /> Payment verified
            </span>
          )}
        </div>
      </Card>
    </Link>
  );
}
