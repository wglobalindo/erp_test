<?php
/* Copyright (C) 2005      Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2005-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2011 Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2013      Florian Henry		<florian.henry@open-concept.pro>
 * Copyright (C) 2015      Marcos García       <marcosgdf@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/expedition/doc/pdf_merou.modules.php
 *	\ingroup    expedition
 *	\brief      Fichier de la classe permettant de generer les bordereaux envoi au modele Merou
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';


/**
 *	Classe permettant de generer les borderaux envoi au modele Merou
 */
class pdf_shipment_no_tax extends ModelePdfExpedition
{
	var $emetteur;	// Objet societe qui emet


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	function __construct($db=0)
	{
		global $conf,$langs,$mysoc;

		$this->db = $db;
		$this->name = "shipment_no_tax";
		$this->description = "shipment no tax";

		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		//$this->page_largeur = $formatarray['width'];
		//$this->page_hauteur = round($formatarray['height']/2);

    $this->page_largeur = "215";
		$this->page_hauteur = "140";

		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=isset($conf->global->MAIN_PDF_MARGIN_LEFT)?$conf->global->MAIN_PDF_MARGIN_LEFT:10;
		$this->marge_droite=isset($conf->global->MAIN_PDF_MARGIN_RIGHT)?$conf->global->MAIN_PDF_MARGIN_RIGHT:10;
		$this->marge_haute =isset($conf->global->MAIN_PDF_MARGIN_TOP)?$conf->global->MAIN_PDF_MARGIN_TOP:10;
		$this->marge_basse =isset($conf->global->MAIN_PDF_MARGIN_BOTTOM)?$conf->global->MAIN_PDF_MARGIN_BOTTOM:10;

		$this->option_logo = 1;

		// Recupere emmetteur
		$this->emetteur=$mysoc;
		if (! $this->emetteur->country_code) $this->emetteur->country_code=substr($langs->defaultlang,-2);    // By default if not defined
	}


	/**
	 *	Function to build pdf onto disk
	 *
	 *	@param		Object		$object			Object expedition to generate (or id if old method)
	 *	@param		Translate	$outputlangs		Lang output object
     *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
     *  @param		int			$hidedetails		Do not show line details
     *  @param		int			$hidedesc			Do not show desc
     *  @param		int			$hideref			Do not show ref
     *  @return     int         	    			1=OK, 0=KO
	 */
	function write_file(&$object,$outputlangs,$srctemplatepath='',$hidedetails=0,$hidedesc=0,$hideref=0)
	{
		global $user,$conf,$langs,$mysoc,$hookmanager;

		$object->fetch_thirdparty();

		if (! is_object($outputlangs)) $outputlangs=$langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("products");
		$outputlangs->load("propal");
		$outputlangs->load("deliveries");
		$outputlangs->load("sendings");
		$outputlangs->load("productbatch");

		if ($conf->expedition->dir_output)
		{
			$object->fetch_thirdparty();

			$origin = $object->origin;

			//Creation de l expediteur
			$this->expediteur = $mysoc;

			//Creation du destinataire
			$idcontact = $object->$origin->getIdContact('external','SHIPPING');
			$this->destinataire = new Contact($this->db);
			if (! empty($idcontact[0])) $this->destinataire->fetch($idcontact[0]);

			//Creation du livreur
			$idcontact = $object->$origin->getIdContact('internal','LIVREUR');
			$this->livreur = new User($this->db);
			if (! empty($idcontact[0])) $this->livreur->fetch($idcontact[0]);

			// Definition de $dir et $file
			if ($object->specimen)
			{
				$dir = $conf->expedition->dir_output."/sending";
				$file = $dir . "/SPECIMEN.pdf";
			}
			else
			{
				$expref = dol_sanitizeFileName($object->ref);
				$dir = $conf->expedition->dir_output . "/sending/" . $expref;
				$file = $dir . "/" . $expref . ".pdf";
			}

			if (! file_exists($dir))
			{
				if (dol_mkdir($dir) < 0)
				{
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir",$dir);
					return 0;
				}
			}

			if (file_exists($dir))
			{
				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('beforePDFCreation',$parameters,$object,$action);    // Note that $action and $object may have been modified by some hooks

				$nblignes = count($object->lines);

				$pdf=pdf_getInstance($this->format,'mm','l');
				$default_font_size = pdf_getPDFFontSize($outputlangs)+4;
				$heightforinfotot = 0;	// Height reserved to output the info and total part
		        $heightforfreetext= (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT)?$conf->global->MAIN_PDF_FREETEXT_HEIGHT:5);	// Height reserved to output the free text on last page
	            $heightforfooter = $this->marge_basse +2 ;	// Height reserved to output the footer (value include bottom margin)

                $pdf->SetAutoPageBreak(1,0);

			    if (class_exists('TCPDF'))
                {
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                }
                $pdf->SetFont(pdf_getPDFFont($outputlangs));
                // Set path to the background PDF File
                if (empty($conf->global->MAIN_DISABLE_FPDI) && ! empty($conf->global->MAIN_ADD_PDF_BACKGROUND))
                {
                    $pagecount = $pdf->setSourceFile($conf->mycompany->dir_output.'/'.$conf->global->MAIN_ADD_PDF_BACKGROUND);
                    $tplidx = $pdf->importPage(1);
                }

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128,128,128);

				if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("Shipment"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("Shipment"));
				if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right

				// New page
				$pdf->AddPage();
				$pagenb++;
				$this->_pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('','', $default_font_size - 3);
				$pdf->MultiCell(0, 3, '');		// Set interline to 3
				$pdf->SetTextColor(0,0,0);

				$tab_top = 52;
				$tab_top_newpage = (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)?15:10);
				$tab_height = $this->page_hauteur - $tab_top - $heightforfooter;
				$tab_height_newpage = $this->page_hauteur - $tab_top_newpage - $heightforfooter;

				$extrafields = new ExtraFields($this->db);
				$extrafields->fetch_name_optionals_label($object->table_element_line);
				$unit_name_array = $extrafields->attributes[$object->table_element_line]['param']['unit']['options'];

				// Affiche notes
				if (! empty($object->note_public))
				{
					$pdf->SetFont('','', $default_font_size - 1);
					$pdf->writeHTMLCell(190, 3, $this->marge_gauche, $tab_top, dol_htmlentitiesbr($object->note_public), 0, 1);
					$nexY = $pdf->GetY();
					$height_note=$nexY-$tab_top;

					// Rect prend une longueur en 3eme param
					$pdf->SetDrawColor(192,192,192);
					$pdf->Rect($this->marge_gauche, $tab_top-1, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_note+1);

					$tab_height = $tab_height - $height_note;
					$tab_top = $nexY+6;
				}
				else
				{
					$height_note=0;
				}


				$pdf->SetFillColor(254,254,254);
				//$pdf->SetTextColor(0,0,0);
				$pdf->SetXY(10, $tab_top + 5);

				$iniY = $tab_top + 7;
				$curY = $tab_top + 7;
				$nexY = $tab_top + 7;

				$num=count($object->lines);
				// Loop on each lines
				for ($i = 0; $i < $num; $i++)
				{
					$object->lines[$i]->fetch_optionals($object->lines[$i]->rowid,$extralabelsline);

					$curY = $nexY;
					$pdf->SetFont('','', $default_font_size - 1);
					$pdf->SetTextColor(0,0,0);

					$pdf->setTopMargin($tab_top_newpage);
					$pdf->setPageOrientation('', 1, $heightforfooter);	// The only function to edit the bottom margin of current page to set it.
					$pageposbefore=$pdf->getPage();

					// Description de la ligne produit

					if ($object->lines[$i]->array_options['options_cn']){
						$pdf->SetXY(30, $curY);
						$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($object->lines[$i]->array_options['options_cn']), 0, 'L', 0);
					}
					else {
						$libelleproduitservice = pdf_writelinedesc($pdf,$object,$i,$outputlangs,115,4,30,$curY,0,1);
					}

					$nexY = $pdf->GetY();
					$pageposafter=$pdf->getPage();
					$pdf->setPage($pageposbefore);
					$pdf->setTopMargin($this->marge_haute);
					$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.

					// We suppose that a too long description is moved completely on next page
					if ($pageposafter > $pageposbefore) {
						$pdf->setPage($pageposafter); $curY = $tab_top_newpage;
					}

					$pdf->SetFont('','', $default_font_size - 1);

					// Check boxes
					$pdf->SetDrawColor(120,120,120);
					$pdf->Rect(10+3, $curY+2, 3, 3);

					//Number
					$pdf->SetXY(20, $curY);
					$pdf->MultiCell(10, 5, $i+1, 0, 'C', 0);


					//Insertion de la reference du produit
					/*$pdf->SetXY(30, $curY);
					$pdf->SetFont('','B', $default_font_size - 3);
					$pdf->MultiCell(24, 3, $outputlangs->convToOutputCharset($object->lines[$i]->ref), 0, 'L', 0);
         			*/

					$pdf->SetXY(120, $curY);
					$pdf->MultiCell(30, 5, $object->lines[$i]->qty_asked, 0, 'C', 0);

					$pdf->SetXY(150, $curY);
					$pdf->MultiCell(30, 5, $object->lines[$i]->qty_shipped, 0, 'C', 0);

					$unit_index = $object->lines[$i]->array_options['options_unit'];
					$unit_name = $unit_name_array[$unit_index];
					$pdf->SetXY(180, $curY);
					$pdf->MultiCell(25, 5, $unit_name, 0, 'C', 0);


					// Detect if some page were added automatically and output _tableau for past pages
					if ( $this->page_hauteur - $heightforfooter -3 > $nexY )
					{
						// Add line
						if (! empty($conf->global->MAIN_PDF_DASH_BETWEEN_LINES) && $i < ($nblignes - 1))
						{
							$pdf->setPage($pageposafter);
							$pdf->SetLineStyle(array('dash'=>'1,1','color'=>array(80,80,80)));
							//$pdf->SetDrawColor(190,190,200);
							$pdf->line($this->marge_gauche, $nexY+1, $this->page_largeur - $this->marge_droite, $nexY+1);
							$pdf->SetLineStyle(array('dash'=>0));


						}
					}

					$nexY+=2;    // Passe espace entre les lignes


					// Detect if some page were added automatically and output _tableau for past pages
					while ($pagenb < $pageposafter)
					{
						$pdf->setPage($pagenb);
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage - 1, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
						}
						//$this->_pagefoot($pdf,$object,$outputlangs,1);
						$pagenb++;
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.
					}
					if (isset($object->lines[$i+1]->pagebreak) && $object->lines[$i+1]->pagebreak)
					{
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage - 1, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
						}
						$this->_pagefoot($pdf,$object,$outputlangs,1);
						// New page
						$pdf->AddPage();
						$pagenb++;
					}
				}
				if (isset($object->lines[$i+1]->pagebreak) && $object->lines[$i+1]->pagebreak && $pagenb == 1)
				{
					$heightforfooter = $this->marge_basse + 0;

				}
				else if($pagenb == 1){
					$heightforfooter = $this->marge_basse + 10;
				}
				else {
					$heightforfooter = $this->marge_basse + 18;
				}
				// Show square
				if ($pagenb == 1)
				{
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 0, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}
				else
				{
					$this->_tableau($pdf, $tab_top_newpage-8, $this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 0, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot  - $heightforfreetext - $heightforfooter + 1;
					/*$this->_tableau($pdf, $tab_top_newpage - 1, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 1, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;*/
				}

				// Pied de page
				$this->_pagefoot($pdf, $object, $outputlangs);
				if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();

				$pdf->Close();

				$pdf->Output($file,'F');

				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation',$parameters,$this,$action);    // Note that $action and $object may have been modified by some hooks

                if (! empty($conf->global->MAIN_UMASK))
                    @chmod($file, octdec($conf->global->MAIN_UMASK));

				$this->result = array('fullpath'=>$file);

				return 1;
			}
			else
			{
				$this->error=$outputlangs->transnoentities("ErrorCanNotCreateDir",$dir);
				return 0;
			}
		}
		else
		{
			$this->error=$outputlangs->transnoentities("ErrorConstantNotDefined","EXP_OUTPUTDIR");
			return 0;
		}
	}

	/**
	 *   Show table for lines
	 *
	 *   @param		PDF			$pdf     		Object PDF
	 *   @param		string		$tab_top		Top position of table
	 *   @param		string		$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		Hide top bar of array
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @return	void
	 */
	function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop=0, $hidebottom=0)
	{
		global $langs;
		$default_font_size = pdf_getPDFFontSize($outputlangs)+4;

		$langs->load("main");
		$langs->load("bills");

		if (empty($hidetop))
		{
			$pdf->SetFont('','B', $default_font_size );
			$pdf->SetXY(10,$tab_top);
			$pdf->MultiCell(10,5,"CK",0,'C',1);
			$pdf->line(20, $tab_top, 20, $tab_top + $tab_height);
			$pdf->SetXY(20,$tab_top);
			$pdf->MultiCell(10,5,"NO",0,'C',1);
			$pdf->line(30, $tab_top, 30, $tab_top + $tab_height);

			/*$pdf->SetXY(20,$tab_top);
			$pdf->MultiCell(20,5,$outputlangs->transnoentities("Ref"),0,'C',1);*/
			
			$pdf->SetXY(30,$tab_top);
			$pdf->MultiCell(90,5,$outputlangs->transnoentities("Description"),0,'C',1);
      		$pdf->line(120, $tab_top, 120, $tab_top + $tab_height);
      		$pdf->SetXY(120,$tab_top);
			$pdf->MultiCell(30,5,$outputlangs->transnoentities("QtyOrdered"),0,'C',1);
      		$pdf->line(150, $tab_top, 150, $tab_top + $tab_height);
			$pdf->SetXY(150,$tab_top);
			$pdf->MultiCell(30,5,$outputlangs->transnoentities("QtyToShip"),0,'C',1);
			$pdf->line(180, $tab_top, 180, $tab_top + $tab_height);
			$pdf->SetXY(180,$tab_top);

			// Unit name 211018 Jay
			$pdf->MultiCell(25,5,$outputlangs->transnoentities("Unit"),0,'C',1);
      		$pdf->line($this->marge_gauche, $tab_top+7, $this->page_largeur-$this->marge_droite, $tab_top+7);
		}
		$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur-$this->marge_droite-$this->marge_gauche, $tab_height);
	}

	/**
	 *   	Show footer of page. Need this->emetteur object
     *
	 *   	@param	PDF			$pdf     			PDF
	 * 		@param	Object		$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @return	void
	 */
	function _pagefoot(&$pdf, $object, $outputlangs,$hidefreetext=0)
	{
		$default_font_size = pdf_getPDFFontSize($outputlangs)+4;
		$pdf->SetFont('','', $default_font_size - 1);
		$pdf->SetY(-23);
		$pdf->MultiCell(150, 3, $outputlangs->transnoentities("GoodStatusDeclaration"), 0, 'L');
		$pdf->SetY(-13);
		/*$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ToAndDate"), 0, 'C');
    $pdf->SetFont('','', $default_font_size);*/
		$pdf->SetY(-13);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("NameAndSignature"), 0, 'L');
    $pdf->SetXY(100,-23);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("HormatKami"), 0, 'R');
		// Show page nb only on iso languages (so default Helvetica font)
        //if (pdf_getPDFFont($outputlangs) == 'Helvetica')
        //{
    	//    $pdf->SetXY(-10,-10);
        //    $pdf->MultiCell(11, 2, $pdf->PageNo().'/'.$pdf->getAliasNbPages(), 0, 'R', 0);
        //}
	}


	/**
	 *  Show top header of page.
	 *
	 *  @param	PDF			$pdf     		Object PDF
	 *  @param  Object		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @return	void
	 */
	function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf, $langs,$hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs)+4;

		pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);

			//Affiche le filigrane brouillon - Print Draft Watermark
		if($object->statut==0 && (! empty($conf->global->SENDING_DRAFT_WATERMARK)) )
		{
            pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',$conf->global->SENDING_DRAFT_WATERMARK);
		}

        $posy=$this->marge_haute;
        $posx=$this->page_largeur-$this->marge_droite-100;

		$Xoff = 90;
		$Yoff = 0;

		$tab4_top = 60;
		$tab4_hl = 6;
		$tab4_sl = 4;
		$line = 2;

		//*********************LOGO****************************
		//$pdf->SetXY($posx,$posy-10);

		//company name START
		$pdf->SetXY($this->marge_gauche,$posy);
		$pdf->SetFont('','B', $default_font_size + 5);
		$pdf->MultiCell(80, 20, "WGROUP", 0, 'L');
		$pdf->SetXY($this->marge_gauche,$posy+6);
		$pdf->MultiCell(80, 20, "depok 16513", 0, 'L');
		//company name END


		//*********************Entete****************************
		//Nom du Document
    /*
    $pdf->SetXY($this->marge_gauche,7);
		$pdf->SetFont('','B', $default_font_size + 2);
		$pdf->SetTextColor(0,0,0);
		$pdf->MultiCell(0, 3, $outputlangs->transnoentities("SendingSheet"), '', 'C');	// Bordereau expedition
    */
    //Num Expedition
		$Yoff = $Yoff;
		$Xoff = 142;
		$posy =35;
		//$pdf->Rect($Xoff, $Yoff, 85, 8);
		$pdf->SetXY($this->marge_gauche,$posy-1);
		$pdf->SetFont('','B', $default_font_size);
		$pdf->SetTextColor(0,0,0);
		$pdf->MultiCell(0, 3, $outputlangs->transnoentities("RefSending").': '.$outputlangs->convToOutputCharset($object->ref), '', 'L');
		//$this->Code39($Xoff+43, $Yoff+1, $object->ref,$ext = true, $cks = false, $w = 0.4, $h = 4, $wide = true);

		//Ref Customer
		$posy+=5;
		$pdf->SetXY($this->marge_gauche,$posy);
		$pdf->MultiCell(0, 8, $outputlangs->transnoentities("RefCustomer").': '.$outputlangs->convToOutputCharset($object->ref_customer), '','L');

		$origin 	= $object->origin;
		$origin_id 	= $object->origin_id;

		// Add list of linked elements
    //$pdf->SetFont('','B', $default_font_size);
  /*  $linkedobjects = pdf_getLinkedObjects($object,$outputlangs);
    if (! empty($linkedobjects))
    {
      foreach($linkedobjects as $linkedobject)
      {
          $reftoshow = $linkedobject["ref_title"].' : '.$linkedobject["ref_value"];

        $posy+=5;
        $pdf->SetXY($this->marge_gauche,$posy);
        $pdf->SetFont('','B', $default_font_size - 1);
        $pdf->MultiCell(0, 8, $reftoshow, '','L');
      }
    }
		$reftoshow = $linkedobject["ref_title"].' : '.$linkedobject["ref_value"];
*/

		//$this->Code39($Xoff+43, $Yoff+1, $object->commande->ref,$ext = true, $cks = false, $w = 0.4, $h = 4, $wide = true);
		//Definition Emplacement du bloc Societe
		$Xoff = 110;
		$blSocX=90;
		$blSocY=24;
		$blSocW=50;
		$blSocX2=$blSocW+$blSocX;

		// Sender name
		/*$pdf->SetTextColor(0,0,0);
		$pdf->SetFont('','B', $default_font_size - 3);
		$pdf->SetXY($blSocX,$blSocY+1);
		$pdf->MultiCell(80, 3, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
		$pdf->SetTextColor(0,0,0);*/

		// Sender properties
		/*$carac_emetteur = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty);

		$pdf->SetFont('','', $default_font_size - 3);
		$pdf->SetXY($blSocX,$blSocY+4);
		$pdf->MultiCell(80, 2, $carac_emetteur, 0, 'L');
    */
    /*
		if ($object->thirdparty->code_client)
		{
			$Yoff+=3;
			//$posy=$Yoff;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,0);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode")." : " . $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
		}
    */
		// Date Expedition
		$Yoff = $Yoff-30;
		$pdf->SetXY($blSocX-80,$blSocY+3);

		$pdf->SetFont('','', $default_font_size);
		$pdf->SetTextColor(0,0,0);
		$pdf->MultiCell(100, 8, $outputlangs->transnoentities("Date")." : " . dol_print_date($object->date_delivery,'day',false,$outputlangs,true), '', 'L');

		$pdf->SetXY($blSocX-80,$blSocY+14);
		$pdf->SetFont('','', $default_font_size);
		$pdf->SetTextColor(0,0,0);
		//$pdf->MultiCell(100, 8, $outputlangs->transnoentities("TrackingNumber")." : " . $object->tracking_number, '', 'L');

		// Deliverer
    /*
		$pdf->SetXY($blSocX-80,$blSocY+20);
		$pdf->SetFont('','', $default_font_size);
		$pdf->SetTextColor(0,0,0);

		if (! empty($object->tracking_number))
		{
			$object->GetUrlTrackingStatus($object->tracking_number);
			if (! empty($object->tracking_url))
			{
				if ($object->shipping_method_id > 0)
				{
					// Get code using getLabelFromKey
					$code=$outputlangs->getLabelFromKey($this->db,$object->shipping_method_id,'c_shipment_mode','rowid','code');

					$label='';
					$label.=$outputlangs->trans("SendingMethod").": ".$outputlangs->trans("SendingMethod".strtoupper($code));
					//var_dump($object->tracking_url != $object->tracking_number);exit;
					if ($object->tracking_url != $object->tracking_number)
					{
						$label.=" : ";
						$label.=$object->tracking_number;
					}
					$pdf->SetFont('','', $default_font_size);
					$pdf->writeHTMLCell(100, 8, '', '', $label, '', 'L');
				}
			}
		}
		else
		{
			$pdf->MultiCell(50, 8, $outputlangs->transnoentities("Deliverer")." ".$outputlangs->convToOutputCharset($this->livreur->getFullName($outputlangs)), '', 'L');
		}
    */

		// Shipping company (My Company)
		$Yoff = $blSocY;
		$blExpX=$Xoff-20;
		$blW=52;
		$Ydef = $Yoff;

		//sender frame
		//$pdf->Rect($blExpX, $Yoff, $blW, 26);

		$object->fetch_thirdparty();

		$extrafieldsline = new ExtraFields($this->db);
		$extralabelsline=$extrafieldsline->fetch_name_optionals_label($object->table_element_line);

		// If SHIPPING contact defined on order, we use it
		$usecontact=false;
		$arrayidcontact=$object->$origin->getIdContact('external','SHIPPING');
		if (count($arrayidcontact) > 0)
		{
			$usecontact=true;
			$result=$object->fetch_contact($arrayidcontact[0]);
		}

		// Recipient name
		// On peut utiliser le nom de la societe du contact
		if ($usecontact && !empty($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT)) {
			$thirdparty = $object->contact;
		} else {
			$thirdparty = $object->thirdparty;
		}

		$carac_client_name=pdfBuildThirdpartyName($thirdparty, $outputlangs);

		$carac_client=pdf_build_address($outputlangs,$this->emetteur,$object->thirdparty,((!empty($object->contact))?$object->contact:null),$usecontact,'target',$object);

		$blDestX=$blExpX+40;
		$blW=90;
		//$Yoff = $Ydef -10 ;
   		 $Yoff =$this->marge_haute;
		// Show Recipient frame
		/*$pdf->SetFont('','B', $default_font_size - 2);
		$pdf->SetXY($blDestX,$Yoff-4);
		$pdf->MultiCell($blW,3, $outputlangs->transnoentities("Recipient"), 0, 'L');*/
		//$pdf->Rect($blDestX, $Yoff, $this->page_largeur-$blDestX-$this->marge_droite, 26);

		// Show recipient name
		$pdf->SetFont('','B', $default_font_size );
		$pdf->SetXY($blDestX,$Yoff);
		$pdf->MultiCell($blW,3, $outputlangs->transnoentities("Recipient").":"." ".$carac_client_name, 0, 'L');

		$posy = $pdf->getY();

		// Show recipient information
		$pdf->SetFont('','', $default_font_size - 3);
		$pdf->SetXY($blDestX,$posy);
		$pdf->MultiCell(0, 0, $carac_client, 0, 'L');
	}
}
