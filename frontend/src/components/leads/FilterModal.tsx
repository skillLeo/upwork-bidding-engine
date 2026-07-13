"use client";

import * as React from "react";
import { X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import { TagInput } from "@/components/ui/TagInput";
import { apiErrorMessage } from "@/lib/api-client";
import {
  createSavedFilter,
  updateSavedFilter,
  deleteSavedFilter,
  type SavedFilterInput,
} from "@/lib/hooks/useSavedFilters";
import type { SavedFilter } from "@/lib/types";

const freshnessOptions = [
  { value: "", label: "Any time" },
  { value: "10", label: "Last 10 minutes" },
  { value: "30", label: "Last 30 minutes" },
  { value: "60", label: "Last hour" },
  { value: "180", label: "Last 3 hours" },
  { value: "1440", label: "Today" },
];

export function FilterModal({
  filter,
  onClose,
  onSaved,
  onDeleted,
}: {
  filter: SavedFilter | null;
  onClose: () => void;
  onSaved: (filter: SavedFilter) => void;
  onDeleted?: (id: number) => void;
}) {
  const [name, setName] = React.useState(filter?.name ?? "");
  const [includeKeywords, setIncludeKeywords] = React.useState<string[]>(
    filter?.criteria.include_keywords ?? [],
  );
  const [excludeKeywords, setExcludeKeywords] = React.useState<string[]>(
    filter?.criteria.exclude_keywords ?? [],
  );
  const [budgetMin, setBudgetMin] = React.useState(filter?.criteria.budget_min?.toString() ?? "");
  const [budgetMax, setBudgetMax] = React.useState(filter?.criteria.budget_max?.toString() ?? "");
  const [paymentVerifiedOnly, setPaymentVerifiedOnly] = React.useState(
    filter?.criteria.payment_verified_only ?? false,
  );
  const [minClientSpend, setMinClientSpend] = React.useState(
    filter?.criteria.min_client_spend?.toString() ?? "",
  );
  const [postedWithin, setPostedWithin] = React.useState(
    filter?.criteria.posted_within_minutes?.toString() ?? "",
  );
  const [isPinned, setIsPinned] = React.useState(filter?.is_pinned ?? true);
  const [isDefault, setIsDefault] = React.useState(filter?.is_default ?? false);
  const [saving, setSaving] = React.useState(false);
  const [deleting, setDeleting] = React.useState(false);

  async function handleSave() {
    if (!name.trim()) {
      toast.error("Give this filter a name.");
      return;
    }

    const input: SavedFilterInput = {
      name: name.trim(),
      is_pinned: isPinned,
      is_default: isDefault,
      criteria: {
        include_keywords: includeKeywords,
        exclude_keywords: excludeKeywords,
        budget_min: budgetMin ? Number(budgetMin) : undefined,
        budget_max: budgetMax ? Number(budgetMax) : undefined,
        payment_verified_only: paymentVerifiedOnly || undefined,
        min_client_spend: minClientSpend ? Number(minClientSpend) : undefined,
        posted_within_minutes: postedWithin ? Number(postedWithin) : undefined,
      },
    };

    setSaving(true);
    try {
      const saved = filter
        ? await updateSavedFilter(filter.id, input)
        : await createSavedFilter(input);
      toast.success(filter ? "Filter updated." : "Filter created.");
      onSaved(saved);
    } catch (error) {
      toast.error(apiErrorMessage(error, "Couldn't save this filter."));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!filter) return;
    setDeleting(true);
    try {
      await deleteSavedFilter(filter.id);
      toast.success("Filter deleted.");
      onDeleted?.(filter.id);
    } catch (error) {
      toast.error(apiErrorMessage(error, "Couldn't delete this filter."));
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center p-4">
      <div className="fixed inset-0 bg-black/40" onClick={onClose} />
      <div className="relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-card border border-border bg-white shadow-popover">
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <h2 className="text-base font-semibold text-text-primary">
            {filter ? "Edit filter" : "New filter"}
          </h2>
          <button
            onClick={onClose}
            className="rounded-full p-1 text-text-tertiary hover:bg-black/5 hover:text-text-primary"
            aria-label="Close"
          >
            <X className="h-4.5 w-4.5" />
          </button>
        </div>

        <div className="space-y-4 px-5 py-4">
          <div>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. PHP / Laravel"
            />
          </div>

          <div>
            <Label>Include keywords</Label>
            <TagInput
              value={includeKeywords}
              onChange={setIncludeKeywords}
              placeholder="Add a keyword and press Enter"
            />
          </div>

          <div>
            <Label>Exclude keywords</Label>
            <TagInput
              value={excludeKeywords}
              onChange={setExcludeKeywords}
              placeholder="Add a keyword and press Enter"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Min budget ($)</Label>
              <Input
                type="number"
                min={0}
                value={budgetMin}
                onChange={(e) => setBudgetMin(e.target.value)}
                placeholder="Any"
              />
            </div>
            <div>
              <Label>Max budget ($)</Label>
              <Input
                type="number"
                min={0}
                value={budgetMax}
                onChange={(e) => setBudgetMax(e.target.value)}
                placeholder="Any"
              />
            </div>
          </div>

          <div>
            <Label>Min client spend ($)</Label>
            <Input
              type="number"
              min={0}
              value={minClientSpend}
              onChange={(e) => setMinClientSpend(e.target.value)}
              placeholder="Any"
            />
          </div>

          <div>
            <Label>Freshness</Label>
            <select
              value={postedWithin}
              onChange={(e) => setPostedWithin(e.target.value)}
              className="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              {freshnessOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          <label className="flex items-center gap-2 text-sm text-text-secondary">
            <input
              type="checkbox"
              checked={paymentVerifiedOnly}
              onChange={(e) => setPaymentVerifiedOnly(e.target.checked)}
              className="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
            />
            Payment verified clients only
          </label>

          <div className="flex gap-4 border-t border-border pt-4">
            <label className="flex items-center gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                checked={isPinned}
                onChange={(e) => setIsPinned(e.target.checked)}
                className="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
              />
              Pin to switcher
            </label>
            <label className="flex items-center gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                checked={isDefault}
                onChange={(e) => setIsDefault(e.target.checked)}
                className="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
              />
              Apply automatically on open
            </label>
          </div>
        </div>

        <div className="flex items-center justify-between border-t border-border px-5 py-4">
          {filter ? (
            <Button variant="danger" size="sm" loading={deleting} onClick={handleDelete}>
              Delete
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button variant="ghost" size="sm" onClick={onClose}>
              Cancel
            </Button>
            <Button size="sm" loading={saving} onClick={handleSave}>
              Save filter
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
