<?php

namespace App\Controller\Admin;

use App\Entity\Source;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SourceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Source::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID');
        yield TextField::new('locale');
        yield IntegerField::new('length');
        yield TextField::new('snippet');
        yield TextField::new('hash');
        // hideOnForm, NOT autocomplete: an edit form renders an association as a choice widget,
        // which hydrates every candidate row. Source::$targets is a OneToMany over a table with
        // ~2.9M rows, so opening /admin/source/{id}/edit tried to load all of them and died at
        // the 512MB limit (OutOfMemoryError in ObjectHydrator/UnitOfWork). Index and detail are
        // unaffected — EasyAdmin only counts there, which is why paging worked and edit did not.
        //
        // Hidden rather than autocompleted because this is the INVERSE side: a Target belongs to
        // its Source and is created by the translation pipeline. There is no case for assigning
        // targets to a source by hand, so the field has no business on the form at all.
        yield AssociationField::new('targets')->hideOnForm();
    }
}
