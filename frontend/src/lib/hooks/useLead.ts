"use client";

import useSWR from "swr";
import { apiClient, fetcher } from "@/lib/api-client";
import { buildQuery } from "@/lib/hooks/useLeads";
import type { Lead, LeadStatus, SavedFilterCriteria } from "@/lib/types";

export function useLead(id: number | string | undefined, criteria?: SavedFilterCriteria) {
  const query = criteria ? buildQuery(criteria) : "";
  const { data, error, isLoading, mutate } = useSWR<{ data: Lead }>(
    id ? `/leads/${id}${query ? `?${query}` : ""}` : null,
    fetcher,
  );
  return { lead: data?.data, isLoading, error, mutate };
}

export async function updateLeadStatus(id: number, status: LeadStatus): Promise<Lead> {
  const res = await apiClient.post<{ data: Lead }>(`/leads/${id}/status`, { status });
  return res.data.data;
}

export async function rescoreLead(id: number): Promise<Lead> {
  const res = await apiClient.post<{ data: Lead }>(`/leads/${id}/rescore`);
  return res.data.data;
}
