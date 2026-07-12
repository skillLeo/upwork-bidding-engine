"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api-client";
import type { AnalyticsResponse } from "@/lib/types";

export function useAnalytics() {
  const { data, error, isLoading } = useSWR<{ data: AnalyticsResponse }>(
    "/analytics",
    fetcher,
  );
  return { analytics: data?.data, isLoading, error };
}
