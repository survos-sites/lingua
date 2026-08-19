<?php

namespace App\Controller\Admin;

use App\Entity\Target;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TargetCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Target::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('key', 'Key');
        // Same OOM as SourceCrudController::$targets, mirrored: this is the OWNING side, so the
        // edit form built a <select> of every candidate Source — ~1.19M rows — and 500'd.
        //
        // autocomplete() rather than hideOnForm(): unlike the inverse side, a Target's source is
        // a real, meaningful field worth seeing and (rarely) correcting. Autocomplete makes the
        // widget AJAX-driven, so it loads only what a search matches instead of the whole table.
        yield AssociationField::new('source')->autocomplete();
        yield TextField::new('targetLocale');
        yield TextField::new('engine');
        yield TextField::new('marking');
        yield TextField::new('snippet');
        yield IntegerField::new('length');
    }
}
