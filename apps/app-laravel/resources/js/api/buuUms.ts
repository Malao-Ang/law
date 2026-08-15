import type { BuuUmsSPersonRequest, BuuUmsSPersonResponse } from '../types/buuUms';

export async function fetchBuuUmsSPerson(
  domain: string,
  token: string,
  params: BuuUmsSPersonRequest,
): Promise<BuuUmsSPersonResponse> {
  const response = await fetch(`https://${domain}/service-api/ums.SPerson`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(params),
  });

  if (!response.ok) {
    throw new Error(`BuuUmsSPerson HTTP ${response.status}`);
  }

  return response.json() as Promise<BuuUmsSPersonResponse>;
}
