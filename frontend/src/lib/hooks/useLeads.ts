"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api-client";
import type { LeadsResponse } from "@/lib/types";

export interface LeadFilters {
  status?: string;
  score_min?: number;
  search?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}

function buildQuery(filters: LeadFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.score_min !== undefined) params.set("score_min", String(filters.score_min));
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

export function useLeads(filters: LeadFilters = {}) {
  const query = buildQuery(filters);
  const { data, error, isLoading, mutate } = useSWR<LeadsResponse>(
    `/leads${query ? `?${query}` : ""}`,
    fetcher,
    { keepPreviousData: true, revalidateOnFocus: true },
  );

  return {
    leads: data?.data ?? [],
    meta: data?.meta,
    isLoading,
    error,
    mutate,
  };
}
