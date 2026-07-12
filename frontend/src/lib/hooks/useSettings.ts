"use client";

import useSWR from "swr";
import { apiClient, fetcher } from "@/lib/api-client";
import type { SettingsResponse, TestConnectionResult } from "@/lib/types";

export function useSettings() {
  const { data, error, isLoading, mutate } = useSWR<{ data: SettingsResponse }>(
    "/settings",
    fetcher,
  );
  return { settings: data?.data, isLoading, error, mutate };
}

export async function saveSettings(
  payload: Record<string, unknown>,
): Promise<SettingsResponse> {
  const res = await apiClient.post<{ data: SettingsResponse; meta: { message: string } }>(
    "/settings",
    payload,
  );
  return res.data.data;
}

export async function testConnection(service: string): Promise<TestConnectionResult> {
  const res = await apiClient.post<{ data: TestConnectionResult }>(
    `/settings/test/${service}`,
  );
  return res.data.data;
}
