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
  include_keywords?: string[];
  exclude_keywords?: string[];
  budget_min?: number;
  budget_max?: number;
  payment_verified_only?: boolean;
  min_client_spend?: number;
  client_countries_include?: string[];
  client_countries_exclude?: string[];
  posted_within_minutes?: number;
}

export function buildQuery(filters: LeadFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.score_min !== undefined) params.set("score_min", String(filters.score_min));
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.include_keywords?.length) {
    params.set("include_keywords", filters.include_keywords.join(","));
  }
  if (filters.exclude_keywords?.length) {
    params.set("exclude_keywords", filters.exclude_keywords.join(","));
  }
  if (filters.budget_min !== undefined) params.set("budget_min", String(filters.budget_min));
  if (filters.budget_max !== undefined) params.set("budget_max", String(filters.budget_max));
  if (filters.payment_verified_only) params.set("payment_verified_only", "1");
  if (filters.min_client_spend !== undefined) {
    params.set("min_client_spend", String(filters.min_client_spend));
  }
  if (filters.client_countries_include?.length) {
    params.set("client_countries_include", filters.client_countries_include.join(","));
  }
  if (filters.client_countries_exclude?.length) {
    params.set("client_countries_exclude", filters.client_countries_exclude.join(","));
  }
  if (filters.posted_within_minutes !== undefined) {
    params.set("posted_within_minutes", String(filters.posted_within_minutes));
  }
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
