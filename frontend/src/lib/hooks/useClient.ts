"use client";

import useSWR from "swr";
import { apiClient, fetcher } from "@/lib/api-client";
import type { Client, Message } from "@/lib/types";

export function useClient(id: number | string | undefined) {
  const { data, error, isLoading, mutate } = useSWR<{ data: Client }>(
    id ? `/clients/${id}` : null,
    fetcher,
  );
  return { client: data?.data, isLoading, error, mutate };
}

export async function draftReply(clientId: number, message: string): Promise<Message> {
  const res = await apiClient.post<{ data: Message }>(
    `/clients/${clientId}/draft-reply`,
    { message },
  );
  return res.data.data;
}
