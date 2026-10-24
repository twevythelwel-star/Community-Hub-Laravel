

import { useState, useEffect } from 'react';
import { format, formatDistanceToNow, parseISO } from 'date-fns';

/**
 * `date` accepts what the implementation has always handled: it calls
 * `new Date(date)` internally, so an ISO string or epoch works. The type said
 * `Date` only because the mock data layer used Date objects — server payloads
 * are ISO strings, and every call site would otherwise need a cast.
 */
type DateLike = Date | string | number;

/**
 * A bare "2026-10-18" is a calendar date, not UTC midnight: `new Date()` would
 * show it as the 17th anywhere west of Greenwich. Timestamps are unchanged.
 */
const toDate = (date: DateLike): Date =>
  typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) ? parseISO(date) : new Date(date);

type ClientFormattedDateProps = {
  date: DateLike;
  formatString: string;
};

export function ClientFormattedDate({ date, formatString }: ClientFormattedDateProps) {
  const [isClient, setIsClient] = useState(false);

  useEffect(() => {
    setIsClient(true);
  }, []);

  if (!isClient) {
    return null; 
  }

  return <span>{format(toDate(date), formatString)}</span>;
}


type ClientFormattedDistanceToNowProps = {
    date: DateLike;
};

export function ClientFormattedDistanceToNow({ date }: ClientFormattedDistanceToNowProps) {
    const [isClient, setIsClient] = useState(false);
  
    useEffect(() => {
      setIsClient(true);
    }, []);
  
    if (!isClient) {
      return null;
    }
  
    return <span>{formatDistanceToNow(toDate(date), { addSuffix: true })}</span>;
  }
