import type { CreditEntry } from '@/types/credits';

export const creditEntryPresentation: Record<
    CreditEntry['type'],
    { label: string; sign: string }
> = {
    purchase: { label: 'Purchase', sign: '+' },
    hold: { label: 'Hold for generation', sign: '−' },
    release: { label: 'Hold released', sign: '+' },
    debit: { label: 'Charge for generation', sign: '−' },
};
