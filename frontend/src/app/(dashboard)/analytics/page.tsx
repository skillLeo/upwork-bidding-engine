"use client";

import { Activity, Award, Send, Sparkles, TicketPercent, Zap } from "lucide-react";
import { useAnalytics } from "@/lib/hooks/useAnalytics";
import { AuthGuard } from "@/components/layout/AuthGuard";
import { PageContainer } from "@/components/layout/Rails";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { StatCard } from "@/components/ui/StatCard";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { TrendChart } from "@/components/analytics/TrendChart";
import { BestJobTypesChart } from "@/components/analytics/BestJobTypesChart";
import { BestHoursChart } from "@/components/analytics/BestHoursChart";
import { relativeTime } from "@/lib/utils";

function AnalyticsContent() {
  const { analytics, isLoading } = useAnalytics();

  if (isLoading || !analytics) {
    return (
      <PageContainer className="space-y-4">
        <Skeleton className="h-7 w-40" />
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full" />
          ))}
        </div>
        <Skeleton className="h-72 w-full" />
      </PageContainer>
    );
  }

  const { summary, trend, best_job_types, best_hours, recent_activity } = analytics;

  return (
    <PageContainer className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-text-primary">Analytics</h1>
        <p className="text-sm text-text-secondary">Last 14 days of pipeline activity.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label="Total leads" value={summary.total_leads} icon={Activity} />
        <StatCard label="Proposals sent" value={summary.proposals_sent} icon={Send} />
        <StatCard
          label="Reply rate"
          value={`${summary.reply_rate}%`}
          icon={Sparkles}
          trend={summary.reply_rate >= 30 ? "up" : "neutral"}
        />
        <StatCard
          label="Win rate"
          value={`${summary.win_rate}%`}
          icon={Award}
          trend={summary.win_rate >= 15 ? "up" : "neutral"}
        />
        <StatCard label="Avg score" value={summary.avg_score || "—"} icon={Zap} />
        <StatCard
          label="Est. Connects spent"
          value={summary.estimated_connects_spent}
          icon={TicketPercent}
          hint="~4 per proposal, estimated"
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Pipeline trend</CardTitle>
        </CardHeader>
        <CardContent>
          <TrendChart data={trend} />
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Best job types</CardTitle>
          </CardHeader>
          <CardContent>
            <BestJobTypesChart data={best_job_types} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Best hours to catch new posts</CardTitle>
          </CardHeader>
          <CardContent>
            <BestHoursChart data={best_hours} />
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Recent activity</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {recent_activity.length === 0 ? (
            <EmptyState title="No activity yet" />
          ) : (
            <ul className="divide-y divide-border">
              {recent_activity.map((entry) => (
                <li key={entry.id} className="flex items-center justify-between gap-3 px-5 py-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-text-primary">
                      {entry.type.replace(/_/g, " ")}
                    </p>
                    {entry.subject_type && (
                      <p className="text-xs text-text-tertiary">
                        {entry.subject_type} #{entry.subject_id}
                      </p>
                    )}
                  </div>
                  <span className="shrink-0 text-xs text-text-tertiary">
                    {relativeTime(entry.created_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </PageContainer>
  );
}

export default function AnalyticsPage() {
  return (
    <AuthGuard adminOnly>
      <AnalyticsContent />
    </AuthGuard>
  );
}
