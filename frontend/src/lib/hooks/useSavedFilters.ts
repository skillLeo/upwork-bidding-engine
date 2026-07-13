"use client";

import useSWR from "swr";
import { apiClient, fetcher } from "@/lib/api-client";
import type { SavedFilter, SavedFilterCriteria } from "@/lib/types";

export function useSavedFilters() {
  const { data, error, isLoading, mutate } = useSWR<{ data: SavedFilter[] }>(
    "/saved-filters",
    fetcher,
  );
  return { filters: data?.data ?? [], isLoading, error, mutate };
}

export interface SavedFilterInput {
  name: string;
  is_default?: boolean;
  is_pinned?: boolean;
  criteria: SavedFilterCriteria;
}

export async function createSavedFilter(input: SavedFilterInput): Promise<SavedFilter> {
  const res = await apiClient.post<{ data: SavedFilter }>("/saved-filters", input);
  return res.data.data;
}

export async function updateSavedFilter(
  id: number,
  input: SavedFilterInput,
): Promise<SavedFilter> {
  const res = await apiClient.put<{ data: SavedFilter }>(`/saved-filters/${id}`, input);
  return res.data.data;
}

export async function deleteSavedFilter(id: number): Promise<void> {
  await apiClient.delete(`/saved-filters/${id}`);
}
