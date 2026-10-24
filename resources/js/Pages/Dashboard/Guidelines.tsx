

import { useState } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { MoreHorizontal, PlusCircle } from "lucide-react";
import type { Guideline } from "@/types";
import { useToast } from '@/hooks/use-toast';
import { submit } from '@/lib/submit';
import { GuidelineForm } from '@/components/dashboard/guideline-form';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
  } from "@/components/ui/dropdown-menu";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from '@/components/ui/alert-dialog';


type GuidelineItem = {
    id: number;
    title: string;
    description: string;
};

type Props = {
    /** Keyed by category, already sorted by the server. */
    guidelines: Record<string, GuidelineItem[]>;
    canManage: boolean;
};

export default function GuidelinesPage({ guidelines: groupedGuidelines, canManage }: Props) {
    const { toast } = useToast();
    const [isFormOpen, setFormOpen] = useState(false);
    const [selectedGuideline, setSelectedGuideline] = useState<Guideline | undefined>(undefined);

    const handleOpenForm = (guideline?: Guideline) => {
        setSelectedGuideline(guideline);
        setFormOpen(true);
    };

    const handleCloseForm = () => {
        setSelectedGuideline(undefined);
        setFormOpen(false);
    };

    const handleSave = (data: Omit<Guideline, 'id'>, id?: string) =>
        id
            ? submit('patch', `/dashboard/guidelines/${id}`, data)
            : submit('post', '/dashboard/guidelines', data);

    const handleDelete = (guideline: GuidelineItem) => {
        submit('delete', `/dashboard/guidelines/${guideline.id}`).then(
            () => toast({ title: 'Guideline Deleted', description: `"${guideline.title}" has been removed.` }),
            () => toast({ variant: 'destructive', title: 'Guideline not deleted', description: 'The server refused the request. Please try again.' }),
        );
    };

    const categories = Object.keys(groupedGuidelines).sort();

  return (
    <DashboardLayout>
      <Head title="Community Guidelines" />
      <div className="grid gap-8 max-w-7xl mx-auto pb-12">
        <div className="flex items-center justify-between">
            <div>
                <h1 className="font-headline text-3xl font-bold">Community Guidelines</h1>
                <p className="text-muted-foreground">A comprehensive guide to the rules and regulations of our community.</p>
            </div>
             {canManage && (
                <GuidelineForm
                    open={isFormOpen}
                    onOpenChange={(open) => (open ? handleOpenForm() : handleCloseForm())}
                    onSave={handleSave}
                    guideline={selectedGuideline}
                >
                    <Button size="sm" className="gap-1">
                        <PlusCircle className="h-3.5 w-3.5" />
                        <span className="sr-only sm:not-sr-only sm:whitespace-nowrap">
                            Add Guideline
                        </span>
                    </Button>
                </GuidelineForm>
             )}
        </div>
        
        <Card>
            <CardHeader>
                <CardTitle>Rules by Category</CardTitle>
                <CardDescription>Click on a category to view the guidelines.</CardDescription>
            </CardHeader>
            <CardContent>
                {categories.length === 0 && (
                    <p className="py-8 text-center text-muted-foreground">No guidelines have been published yet.</p>
                )}
                <Accordion type="single" collapsible className="w-full">
                    {categories.map(category => (
                        <AccordionItem value={category} key={category}>
                            <AccordionTrigger className="text-lg font-semibold">
                               {category}
                            </AccordionTrigger>
                            <AccordionContent className="pt-4 space-y-4">
                                {groupedGuidelines[category].map(guideline => (
                                    <div key={guideline.id} className="border rounded-md p-4 flex justify-between items-start">
                                        <div>
                                            <h4 className="font-semibold">{guideline.title}</h4>
                                            <p className="text-muted-foreground mt-1">{guideline.description}</p>
                                        </div>
                                        {canManage && (
                                             <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                <Button aria-haspopup="true" size="icon" variant="ghost">
                                                    <MoreHorizontal className="h-4 w-4" />
                                                    <span className="sr-only">Toggle menu</span>
                                                </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                    <DropdownMenuItem onClick={() => handleOpenForm({ ...guideline, id: String(guideline.id), category })}>Edit</DropdownMenuItem>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <DropdownMenuItem onSelect={(e) => e.preventDefault()} className="text-red-600">Delete</DropdownMenuItem>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                             <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                                                             <AlertDialogDescription>
                                                                 This action cannot be undone. This will permanently delete the guideline titled &ldquo;{guideline.title}&rdquo;.
                                                             </AlertDialogDescription>
                                                             </AlertDialogHeader>
                                                             <AlertDialogFooter>
                                                             <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                             <AlertDialogAction onClick={() => handleDelete(guideline)}>
                                                                 Yes, delete
                                                             </AlertDialogAction>
                                                             </AlertDialogFooter>
                                                         </AlertDialogContent>
                                                     </AlertDialog>
                                                 </DropdownMenuContent>
                                             </DropdownMenu>
                                        )}
                                    </div>
                                ))}
                            </AccordionContent>
                        </AccordionItem>
                    ))}
                </Accordion>
            </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
