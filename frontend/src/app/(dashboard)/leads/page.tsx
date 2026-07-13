"use client";

import * as React from "react";
import {
  createColumnHelper,
  getCoreRowModel,
  useReactTable,
  type SortingState,
} from "@tanstack/react-table";
import { Inbox, Search, Sparkles, TrendingUp } from "lucide-react";
import { useLeads } from "@/lib/hooks/useLeads";
import { useSavedFilters } from "@/lib/hooks/useSavedFilters";
import { LeadCard } from "@/components/leads/LeadCard";
import { LeadFiltersRail } from "@/components/leads/LeadFiltersRail";
import { SavedFiltersBar } from "@/components/leads/SavedFiltersBar";
import { LeftRail, PageContainer, RightRail } from "@/components/layout/Rails";
import { Card } from "@/components/ui/Card";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { LeadCardSkeleton } from "@/components/ui/Skeleton";
import type { Lead, SavedFilter } from "@/lib/types";

const TIPS = [
  "Proposals sent within the first hour get read far more often — check Ready leads often.",
  "Mention one specific detail from the brief in your opener; generic proposals get skipped.",
  "A short, confident proposal beats a long one. Aim for four tight paragraphs.",
  "If the client's hire rate is 0%, ask a clarifying question before committing to scope.",
  "Always end with one clear, low-friction next step — a question, not just \"let me know\".",
  "Archived isn't wasted — check score_reason to see if a rule needs tuning in Settings.",
];

const sortOptions = [
  { value: "-created_at", label: "Newest first" },
  { value: "-score", label: "Highest score" },
  { value: "-proposal_count", label: "Most proposals" },
  { value: "posted_at", label: "Oldest posted" },
];

function useDebounced<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = React.useState(value);
  React.useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

const columnHelper = createColumnHelper<Lead>();
const columns = [
  columnHelper.accessor("created_at", { id: "created_at" }),
  columnHelper.accessor("score", { id: "score" }),
  columnHelper.accessor("proposal_count", { id: "proposal_count" }),
  columnHelper.accessor("posted_at", { id: "posted_at" }),
];

export default function LeadsPage() {
  const [status, setStatus] = React.useState<string>("all");
  const [scoreMin, setScoreMin] = React.useState<number | undefined>(undefined);
  const [searchInput, setSearchInput] = React.useState("");
  const search = useDebounced(searchInput);
  const [page, setPage] = React.useState(1);
  const [sorting, setSorting] = React.useState<SortingState>([
    { id: "created_at", desc: true },
  ]);

  const { filters: savedFilters } = useSavedFilters();
  const [activeFilterId, setActiveFilterId] = React.useState<number | null>(null);
  const [hasAppliedDefault, setHasAppliedDefault] = React.useState(false);

  // Apply the pinned default filter once, the first time saved filters load -
  // never again after, so a user explicitly clearing back to "All leads"
  // doesn't get silently overridden back to the default on a later refetch.
  React.useEffect(() => {
    if (hasAppliedDefault || savedFilters.length === 0) return;
    const defaultFilter = savedFilters.find((f) => f.is_default);
    if (defaultFilter) setActiveFilterId(defaultFilter.id);
    setHasAppliedDefault(true);
  }, [savedFilters, hasAppliedDefault]);

  const activeFilter = savedFilters.find((f) => f.id === activeFilterId) ?? null;

  function handleSelectFilter(filter: SavedFilter | null) {
    setActiveFilterId(filter?.id ?? null);
    setHasAppliedDefault(true);
  }

  React.useEffect(() => {
    setPage(1);
  }, [status, scoreMin, search, sorting, activeFilterId]);

  const sortParam =
    sorting.length > 0 ? `${sorting[0].desc ? "-" : ""}${sorting[0].id}` : "-created_at";

  const { leads, meta, isLoading } = useLeads({
    status: status === "all" ? undefined : status,
    score_min: scoreMin,
    search: search || undefined,
    sort: sortParam,
    page,
    per_page: 12,
    include_keywords: activeFilter?.criteria.include_keywords,
    exclude_keywords: activeFilter?.criteria.exclude_keywords,
    budget_min: activeFilter?.criteria.budget_min,
    budget_max: activeFilter?.criteria.budget_max,
    payment_verified_only: activeFilter?.criteria.payment_verified_only,
    min_client_spend: activeFilter?.criteria.min_client_spend,
    posted_within_minutes: activeFilter?.criteria.posted_within_minutes,
  });

  const table = useReactTable({
    data: leads,
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualSorting: true,
    manualFiltering: true,
    pageCount: meta?.last_page ?? -1,
    state: { sorting },
    onSortingChange: setSorting,
  });

  const rows = table.getRowModel().rows;

  function clearFilters() {
    setStatus("all");
    setScoreMin(undefined);
    setSearchInput("");
    handleSelectFilter(null);
  }

  return (
    <PageContainer>
      <div className="flex items-start gap-4">
        <LeftRail>
          <LeadFiltersRail
            status={status}
            onStatusChange={setStatus}
            scoreMin={scoreMin}
            onScoreMinChange={setScoreMin}
          />
        </LeftRail>

        <div className="min-w-0 flex-1 space-y-4">
          <SavedFiltersBar activeId={activeFilterId} onSelect={handleSelectFilter} />

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 className="text-xl font-semibold text-text-primary">Leads</h1>
              <p className="text-sm text-text-secondary">
                {meta ? `${meta.total} lead${meta.total === 1 ? "" : "s"}` : "Loading…"}
              </p>
            </div>
            <div className="flex gap-2">
              <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
                <Input
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  placeholder="Search leads…"
                  className="w-full pl-9 sm:w-56"
                />
              </div>
              <select
                value={sortParam}
                onChange={(e) => {
                  const v = e.target.value;
                  setSorting([{ id: v.replace("-", ""), desc: v.startsWith("-") }]);
                }}
                className="h-10 rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
              >
                {sortOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <LeadCardSkeleton key={i} />
              ))}
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={Inbox}
              title="No leads match these filters"
              description="Try widening your status or score filter, or clear your search."
              action={
                <Button variant="secondary" onClick={clearFilters}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <div className="space-y-3">
              {rows.map((row) => (
                <LeadCard key={row.original.id} lead={row.original} activeFilterId={activeFilterId} />
              ))}
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between pt-2">
              <Button
                variant="ghost"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </Button>
              <span className="text-xs text-text-tertiary">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <Button
                variant="ghost"
                size="sm"
                disabled={page >= meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          )}
        </div>

        <RightRail>
          <Card className="p-4">
            <p className="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
              <TrendingUp className="h-4 w-4 text-primary" /> Quick stats
            </p>
            <div className="mt-3 space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-text-secondary">Matching filters</span>
                <span className="font-medium text-text-primary">{meta?.total ?? "—"}</span>
              </div>
            </div>
          </Card>
          <Card className="p-4">
            <p className="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
              <Sparkles className="h-4 w-4 text-primary" /> Tip of the day
            </p>
            <p className="mt-2 text-sm text-text-secondary">
              {TIPS[new Date().getDate() % TIPS.length]}
            </p>
          </Card>
        </RightRail>
      </div>
    </PageContainer>
  );
}
