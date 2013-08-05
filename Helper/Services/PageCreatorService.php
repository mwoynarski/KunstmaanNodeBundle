<?php

namespace Kunstmaan\NodeBundle\Helper\Services;

use Kunstmaan\NodeBundle\Entity\HasNodeInterface,
    Kunstmaan\NodeBundle\Entity\Node;

use Kunstmaan\NodeBundle\Repository\NodeRepository,
    Kunstmaan\NodeBundle\Helper\Services\ACLPermissionCreatorService;

use Kunstmaan\PagePartBundle\Helper\HasPagePartsInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface,
    Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service to create new pages.
 */
class PageCreatorService Implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @param HasNodeInterface $pageTypeInstance The page
     * @param array            $translations     containing arrays. Sample:
     * [
     *  [   "language" => "nl",
     *      "callback" => function($page, $translation) {
     *          $translation->setTitle('NL titel');
     *      }
     *  ],
     *  [   "language" => "fr",
     *      "callback" => function($page, $translation) {
     *          $translation->setTitle('FR titel');
     *      }
     *  ]
     * ]
     * @param array            $options          -
     *      parent: type node, nodetransation or page.
     *      page_internal_name: string. name the page will have in the database.
     *      set_online: bool. if true the page will be set as online after creation.
     *      hidden_from_nav: bool. if true the page will not be show in the navigation
     *      creator: username
     *
     * Automatically calls the ACL + sets the slugs to empty when the page is an Abstract node.
     *
     * @return Node The new node for the page.
     *
     * @throws \InvalidArgumentException
     */
    public function createPage(HasNodeInterface $pageTypeInstance, array $translations, array $options = array())
    {
        if (is_null($options)) {
            $options = array();
        }

        if (is_null($translations) or (count($translations) == 0)) {
            throw new \InvalidArgumentException('There has to be at least 1 translation in the translations array');
        }

        // TODO: Wrap it all in a transaction.
        $em = $this->container->get('doctrine.orm.entity_manager');

        /* @var NodeRepository $nodeRepo */
        $nodeRepo = $em->getRepository('KunstmaanNodeBundle:Node');
        $userRepo = $em->getRepository('KunstmaanAdminBundle:User');
        $seoRepo = $em->getRepository('KunstmaanSeoBundle:Seo');

        $pagecreator = array_key_exists('creator', $options) ? $options['creator'] : 'pagecreator';
        $creator = $userRepo->findOneBy(array('username' => $pagecreator));

        $parent = isset($options['parent']) ? $options['parent'] : null;

        $pageInternalName = isset($options['page_internal_name']) ? $options['page_internal_name'] : '';

        $setOnline = isset($options['set_online']) ? $options['set_online'] : false;

        // We need to get the language of the first translation so we can create the rootnode.
        // This will also create a translationnode for that language attached to the rootnode.
        $first = true;
        $rootNode = null;

        /* @var \Kunstmaan\NodeBundle\Repository\NodeTranslationRepository $nodeTranslationRepo*/
        $nodeTranslationRepo = $em->getRepository('KunstmaanNodeBundle:NodeTranslation');

        foreach ($translations as $translation) {
            $language = $translation['language'];
            $callback = $translation['callback'];

            $translationNode = null;
            if ($first) {
                $first = false;

                $em->persist($pageTypeInstance);
                $em->flush();

                // Fetch the translation instead of creating it.
                // This returns the rootnode.
                $rootNode = $nodeRepo->createNodeFor($pageTypeInstance, $language, $creator, $pageInternalName);

                if (array_key_exists('hidden_from_nav', $options)) {
                    $rootNode->setHiddenFromNav($options['hidden_from_nav']);
                }

                if (!is_null($parent)) {
                    if ($parent instanceof HasPagePartsInterface) {
                        $parent = $nodeRepo->getNodeFor($parent);
                    }
                    $rootNode->setParent($parent);
                }

                $em->persist($rootNode);
                $em->flush();

                $translationNode = $rootNode->getNodeTranslation($language, true);
            } else {
                // Clone the $pageTypeInstance.
                $pageTypeInstance = clone $pageTypeInstance;

                $em->persist($pageTypeInstance);
                $em->flush();

                // Create the translationnode.
                $translationNode = $nodeTranslationRepo->createNodeTranslationFor($pageTypeInstance, $language, $rootNode, $creator);
            }

            // Make SEO.
            $seo = null;

            if (!is_null($seoRepo)) {
                $seo = $seoRepo->findOrCreateFor($pageTypeInstance);
            }

            $callback($pageTypeInstance, $translationNode, $seo);

            $em->persist($translationNode);
            $em->flush();

            $translationNode->setOnline($setOnline);

            if (!is_null($seo)) {
                $em->persist($seo);
            }

            $em->persist($translationNode);
            $em->flush();
        }

        // ACL
        $aclService = new ACLPermissionCreatorService();
        $aclService->setContainer($this->container);
        $aclService->createPermission($rootNode);

        return $rootNode;
    }

    /**
     * Sets the Container.
     *
     * @param ContainerInterface $container A ContainerInterface instance
     *
     * @api
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }
}
