"use client";

import useSWR from "swr";
import { apiClient, fetcher } from "@/lib/api-client";
import type { Lead, LeadStatus } from "@/lib/types";

export function useLead(id: number | string | undefined) {
  const { data, error, isLoading, mutate } = useSWR<{ data: Lead }>(
    id ? `/leads/${id}` : null,
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
