import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { DateField } from '@/components/dashboard/date-field';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const idTypes = ["National ID", "Driver's License", "Passport"] as const;

const formSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters.'),
  job: z.string().min(2, 'Job/role must be at least 2 characters.'),
  property: z.string().min(3, 'Property is required.'),
  photoUrl: z.string().url().optional(),
  status: z.enum(['Active', 'Inactive', 'Expired ID']),
  idType: z.enum(idTypes),
  idNumber: z.string().min(5, 'ID number is required.'),
  idExpiry: z.date({ required_error: 'ID expiry date is required.' }),
});

export type StaffFormValues = z.infer<typeof formSchema>;

type FormValues = StaffFormValues;

/**
 * Existing record to edit. Loosened from the mock-data `Staff` type: server rows
 * carry a numeric id and an ISO date string where the mock used a string id and
 * a `Date`, so the expiry is accepted in either form and normalised below.
 */
export type StaffFormSubject = {
  id: number | string;
  name: string;
  job: string;
  property: string;
  status: 'Active' | 'Inactive' | 'Expired ID';
  idType?: string;
  idNumber?: string;
  idExpiry?: string | Date;
  photoUrl?: string | null;
};

type StaffFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Receives camelCase form values. The caller is responsible for mapping them
   * to the snake_case payload the controller validates.
   */
  onSave: (data: StaffFormValues, id?: number | string) => void;
  staff?: StaffFormSubject;
};

export function StaffForm({ children, open, onOpenChange, onSave, staff }: StaffFormProps) {
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { status: 'Active', photoUrl: '' },
  });

  function onSubmit(values: FormValues) {
    onSave(values, staff?.id);
    onOpenChange(false);
  }

  return (
    <FormDialog
      trigger={children}
      open={open}
      onOpenChange={onOpenChange}
      form={form}
      onSubmit={onSubmit}
      record={staff}
      resetValues={() => staff
        ? {
            name: staff.name,
            job: staff.job,
            property: staff.property,
            status: staff.status,
            idType: staff.idType as FormValues['idType'],
            idNumber: staff.idNumber ?? '',
            // The server sends an ISO string; the date picker needs a Date.
            idExpiry: staff.idExpiry ? new Date(staff.idExpiry) : undefined,
            photoUrl: staff.photoUrl || '',
          }
        : {
            name: '',
            job: '',
            property: '',
            photoUrl: 'https://picsum.photos/seed/newstaff/200',
            status: 'Active' as const,
            idType: undefined,
            idNumber: '',
            idExpiry: undefined,
          }}
      title={staff ? 'Edit Staff Details' : 'Register New Staff'}
      description="Fill in the details to register a staff member for gate access."
      submitLabel="Save Staff"
    >
      <TextField control={form.control} name="name" label="Full Name" placeholder="e.g., John Smith" />
      <TextField control={form.control} name="job" label="Job / Role" placeholder="e.g., Gardener, Nanny" />
      <TextField control={form.control} name="property" label="Property" placeholder="e.g., Lot 42, Main St" />
      <FormField
        control={form.control}
        name="idType"
        render={({ field }) => (
          <FormItem>
            <FormLabel>ID Type</FormLabel>
            <Select onValueChange={field.onChange} defaultValue={field.value}>
              <FormControl>
                <SelectTrigger>
                  <SelectValue placeholder="Select an ID type" />
                </SelectTrigger>
              </FormControl>
              <SelectContent>
                {idTypes.map(type => (
                  <SelectItem key={type} value={type}>{type}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FormMessage />
          </FormItem>
        )}
      />
      <TextField control={form.control} name="idNumber" label="ID Number" />
      <DateField control={form.control} name="idExpiry" label="ID Expiry Date" />
    </FormDialog>
  );
}
