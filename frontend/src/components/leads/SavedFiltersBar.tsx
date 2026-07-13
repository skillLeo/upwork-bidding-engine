"use client";

import * as React from "react";
import { ChevronDown, Pencil, Pin, Plus } from "lucide-react";
import { cn } from "@/lib/utils";
import { useSavedFilters } from "@/lib/hooks/useSavedFilters";
import { FilterModal } from "@/components/leads/FilterModal";
import type { SavedFilter } from "@/lib/types";

export function SavedFiltersBar({
  activeId,
  onSelect,
}: {
  activeId: number | null;
  onSelect: (filter: SavedFilter | null) => void;
}) {
  const { filters, mutate } = useSavedFilters();
  const [menuOpen, setMenuOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<SavedFilter | "new" | null>(null);

  const pinned = filters.filter((f) => f.is_pinned);

  function handleSaved(saved: SavedFilter) {
    mutate();
    onSelect(saved);
    setEditing(null);
  }

  function handleDeleted(id: number) {
    mutate();
    if (activeId === id) onSelect(null);
    setEditing(null);
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <button
        onClick={() => onSelect(null)}
        className={cn(
          "rounded-pill border px-3 py-1.5 text-xs font-medium transition-colors",
          activeId === null
            ? "border-primary bg-primary-tint text-primary"
            : "border-border-strong text-text-secondary hover:bg-black/5",
        )}
      >
        All leads
      </button>

      {pinned.map((filter) => {
        const isActive = activeId === filter.id;
        return (
          <button
            key={filter.id}
            onClick={() => onSelect(filter)}
            className={cn(
              "group flex items-center gap-1 rounded-pill border px-3 py-1.5 text-xs font-medium transition-colors",
              isActive
                ? "border-primary bg-primary-tint text-primary"
                : "border-border-strong text-text-secondary hover:bg-black/5",
            )}
          >
            {filter.name}
            {filter.is_default && <Pin className="h-3 w-3" />}
            {isActive && (
              <Pencil
                className="h-3 w-3 opacity-0 group-hover:opacity-100"
                onClick={(e) => {
                  e.stopPropagation();
                  setEditing(filter);
                }}
              />
            )}
          </button>
        );
      })}

      <button
        onClick={() => setEditing("new")}
        className="flex items-center gap-1 rounded-pill border border-dashed border-border-strong px-3 py-1.5 text-xs font-medium text-text-secondary hover:bg-black/5"
      >
        <Plus className="h-3 w-3" /> New filter
      </button>

      <div className="relative">
        <button
          onClick={() => setMenuOpen((v) => !v)}
          className="flex items-center gap-1 rounded-pill px-2 py-1.5 text-xs font-medium text-text-tertiary hover:bg-black/5"
        >
          Manage <ChevronDown className="h-3 w-3" />
        </button>

        {menuOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
            <div className="absolute left-0 z-20 mt-1 w-64 rounded-card border border-border bg-white py-1.5 shadow-popover">
              {filters.length === 0 && (
                <p className="px-3 py-2 text-xs text-text-tertiary">No saved filters yet.</p>
              )}
              {filters.map((filter) => (
                <button
                  key={filter.id}
                  onClick={() => {
                    setEditing(filter);
                    setMenuOpen(false);
                  }}
                  className="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-text-secondary hover:bg-black/5"
                >
                  <span className="flex items-center gap-1.5 truncate">
                    {filter.name}
                    {filter.is_default && (
                      <span className="rounded-pill bg-primary-tint px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                        default
                      </span>
                    )}
                  </span>
                  <Pencil className="h-3.5 w-3.5 shrink-0 text-text-tertiary" />
                </button>
              ))}
            </div>
          </>
        )}
      </div>

      {editing && (
        <FilterModal
          filter={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={handleSaved}
          onDeleted={handleDeleted}
        />
      )}
    </div>
  );
}
